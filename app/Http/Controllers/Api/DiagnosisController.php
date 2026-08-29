<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QuizService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class DiagnosisController extends Controller
{
    private string $ApiUrl = 'http://185.222.163.113:8000';

    // دریافت لیست پزشکانی که این پزشک را پیشنهاد داده‌اند
    public function getRecommendations($id)
    {
        $recommenders = DB::table('doctor_recommendations')
            ->join('users', 'doctor_recommendations.recommender_id', '=', 'users.id')
            ->join('doctor_info', 'users.id', '=', 'doctor_info.user_id')
            ->leftJoin('specialties', 'doctor_info.specialty_id', '=', 'specialties.id') // اضافه کردن جوین با جدول تخصص‌ها
            ->where('doctor_recommendations.recommended_id', $id)
            ->select(
                'users.id',
                'users.name',
                'specialties.name as specialty_name', // دریافت نام تخصص
                'doctor_info.image_url'
            )
            ->get();

        // بررسی اینکه آیا کاربر فعلی (اگر پزشک است) این پزشک را پیشنهاد داده یا خیر
        $isRecommendedByMe = false;
        $userId = auth()->id();

        if ($userId) {
            $exists = DB::table('doctor_recommendations')
                ->where('recommender_id', $userId)
                ->where('recommended_id', $id)
                ->exists();
            $isRecommendedByMe = $exists;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'recommenders' => $recommenders,
                'is_recommended_by_me' => $isRecommendedByMe
            ]
        ]);
    }


    // ثبت یا لغو پیشنهاد پزشک
    public function toggleRecommendation(Request $request, $id)
    {
        $recommenderId = auth()->id();

        // اطمینان از اینکه کاربر درخواست دهنده خودش پزشک است (بر اساس نقش)
        $userRole = DB::table('users')->where('id', $recommenderId)->value('role');
        if ($userRole !== 'doctor') {
            return response()->json(['message' => 'فقط پزشکان می‌توانند همکاران را پیشنهاد دهند'], 403);
        }

        if ($recommenderId == $id) {
            return response()->json(['message' => 'شما نمی‌توانید خودتان را پیشنهاد دهید'], 400);
        }

        $exists = DB::table('doctor_recommendations')
            ->where('recommender_id', $recommenderId)
            ->where('recommended_id', $id)
            ->exists();

        if ($exists) {
            // حذف پیشنهاد (لغو)
            DB::table('doctor_recommendations')
                ->where('recommender_id', $recommenderId)
                ->where('recommended_id', $id)
                ->delete();

            return response()->json(['success' => true, 'message' => 'پیشنهاد شما لغو شد', 'is_recommended' => false]);
        } else {
            // ثبت پیشنهاد جدید
            DB::table('doctor_recommendations')->insert([
                'recommender_id' => $recommenderId,
                'recommended_id' => $id,
                'created_at' => now()
            ]);

            return response()->json(['success' => true, 'message' => 'پزشک با موفقیت پیشنهاد داده شد', 'is_recommended' => true]);
        }
    }
    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'required|string',
            'session_id' => 'nullable|string|uuid', // ← اضافه کن
        ]);

        $messages = $validated['messages'];

        // اگر فرانت session_id فرستاده، استفاده کن؛ وگرنه یکی جدید بساز
        $sessionId = $validated['session_id'] ?? (string) Str::uuid();

        $firstUserMsg = collect($messages)->last(fn($m) => $m['role'] === 'user');
        $history = collect($messages)->slice(1)->values()->toArray();

        try {
            DB::table('ai_messages')->insert([
                'user_id'    => auth()->id(),
                'session_id' => $sessionId, // ← از session_id یکتا استفاده کن
                'role'       => 'user',
                'content'    => $firstUserMsg['content'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $response = Http::timeout(30)
                ->post("http://185.222.163.113:8000/chat", [
                    'symptoms' => $firstUserMsg['content'],
                    'history'  => $history,
                ]);

            if (!$response->successful()) {
                return response()->json(['success' => false, 'message' => 'خطا در دریافت پاسخ'], 500);
            }

            $data = $response->json();
            $status = $data['status'] ?? null;
            $diagnosisData = null;

            if (($data['status'] ?? null) === 'complete' && isset($data['diagnosis'])) {
                $data = $this->enrichDiagnosisData($data, 1);
                $diagnosisData = $data;

                if (auth()->check()) {
                    $userId = auth()->id();
                    $key = "diagnosis_{$userId}_" . now()->timestamp;
                    Cache::put($key, $data, now()->addDays(7));

                    $userKeysKey = "user_diagnosis_keys_{$userId}";
                    $keys = Cache::get($userKeysKey, []);
                    $keys[] = $key;
                    Cache::put($userKeysKey, $keys, now()->addDays(7));
                }
            }

            DB::table('ai_messages')->insert([
                'user_id'        => auth()->id(),
                'session_id'     => $sessionId, // ← همان session_id
                'role'           => 'assistant',
                'content'        => $data['message'] ?? '',
                'status'         => $status,
                'diagnosis_data' => $diagnosisData ? json_encode($diagnosisData) : null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            return response()->json([
                'success' => true,
                'data' => $data,
                'session_id' => $sessionId, // ← برگردون به فرانت
            ]);

        } catch (\Exception $e) {
            Log::error('Chat API Error', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'خطا در پردازش درخواست'], 500);
        }
    }


    /**
     * دریافت تشخیص از API خارجی و غنی‌سازی با داده‌های دیتابیس
     */
    public function diagnose(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symptoms' => 'required|string|min:10',
            'age' => 'nullable|integer|min:1|max:120',
            'gender' => 'nullable|in:male,female',
            'medical_history' => 'nullable|string|max:1000',
        ]);

        try {

            // فراخوانی API خارجی
            $response = Http::timeout(30)
                ->post("http://185.222.163.113:8000/diagnose", $validated);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'خطا در دریافت پاسخ از سرویس تشخیص'
                ], 500);
            }

            $diagnosisData = $response->json();

            // غنی‌سازی داده با اطلاعات دیتابیس
            $enrichedData = $this->enrichDiagnosisData($diagnosisData);
            $enrichedData['user_symptoms']=$request->input('symptoms');
            $enrichedData['medical_history']=$request->input('medical_history');

            // کش کردن نتیجه برای این کاربر (اختیاری)
            if (auth()->check()) {
                $userId = auth()->id();
                $timestamp = now()->timestamp;
                $key = "diagnosis_{$userId}_{$timestamp}";

                // ذخیره داده تشخیص
                Cache::put($key, $enrichedData, now()->addDays(7));

                // ذخیره لیست کلیدها برای این کاربر
                $userKeysKey = "user_diagnosis_keys_{$userId}";
                $existingKeys = Cache::get($userKeysKey, []);
                $existingKeys[] = $key;
                Cache::put($userKeysKey, $existingKeys, now()->addDays(7));

                // لاگ برای دیباگ
                Log::info('Diagnosis cached', [
                    'key' => $key,
                    'user_id' => $userId,
                    'has_cache' => Cache::has($key)
                ]);
            }


            return response()->json([
                'success' => true,
                'data' => $enrichedData
            ]);

        } catch (\Exception $e) {
            Log::error('Diagnosis API Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'خطا در پردازش درخواست'
            ], 500);
        }
    }

    /**
     * غنی‌سازی داده‌های تشخیص با اطلاعات دکترها و آزمایشگاه‌ها
     */
    private function enrichDiagnosisData(array $diagnosisData,$type = 0): array
    {

        if ($type == 0)
        {
            $primarySpecialty = $diagnosisData['specialty']['primary'] ?? null;
        }
        else{
            $primarySpecialty = $diagnosisData['diagnosis']['specialty']['primary'] ?? null;
        }
        if (!$primarySpecialty) {
            return $diagnosisData;
        }
        $quiz=new QuizService();
        $form=$quiz->getFormBySpecialty($primarySpecialty);
        // دریافت تخصص از دیتابیس
        $specialty = DB::table('specialties')
            ->where('name', 'LIKE', "%{$primarySpecialty}%")
            ->orWhere('specialty_key', 'LIKE', "%{$primarySpecialty}%")
            ->first();

        if (!$specialty) {
            return $diagnosisData;
        }

        // دریافت دکترهای مرتبط با این تخصص
        $doctors = DB::table('doctor_info')
            ->join('users', 'doctor_info.user_id', '=', 'users.id')
            ->where('doctor_info.specialty_id', $specialty->id)
            ->whereNull('users.deleted_at')
            ->select(
                'doctor_info.id',
                'doctor_info.user_id',
                'doctor_info.name',
                'doctor_info.image_url',
                'doctor_info.rating',
                'doctor_info.visit_price',
                'doctor_info.experience',
                'doctor_info.is_vip',
                'users.email',
                'doctor_info.phone'
            )
            ->orderByDesc('doctor_info.is_vip')
            ->orderByDesc('doctor_info.rating')
            ->limit(3)
            ->get()
            ->map(function ($doctor) {
                return [
                    'id' => $doctor->user_id,
                    'name' => $doctor->name,
                    'image_url' => $doctor->image_url,
                    'rating' => (float) $doctor->rating,
                    'visit_price' => $doctor->visit_price,
                    'experience' => $doctor->experience,
                    'is_vip' => (bool) $doctor->is_vip,
                ];
            })
            ->toArray();

        // دریافت آزمایشگاه‌های مرتبط
        $labs = DB::table('medical_centers')
            ->join('service_types', 'medical_centers.service_type_id', '=', 'service_types.id')
            ->where('service_types.name', 'آزمایشگاه')
            ->where(function ($query) use ($specialty) {
                $query->where('medical_centers.specialty_id', $specialty->id)
                    ->orWhereNull('medical_centers.specialty_id');
            })
            ->select(
                'medical_centers.id',
                'medical_centers.name',
                'medical_centers.address',
                'medical_centers.phone',
                'medical_centers.rating',
                'medical_centers.image_url'
            )
            ->orderByDesc('medical_centers.rating')
            ->limit(3)
            ->get()
            ->map(function ($lab) {
                return [
                    'id' => $lab->id,
                    'name' => $lab->name,
                    'address' => $lab->address,
                    'phone' => $lab->phone,
                    'rating' => (float) $lab->rating,
                    'image_url' => $lab->image_url,
                ];
            })
            ->toArray();

        // افزودن داده‌های غنی‌شده
        $diagnosisData['specialty']['specialty_id'] = $specialty->id;
        $diagnosisData['specialty']['specialty_name_fa'] = $specialty->name;
        $diagnosisData['recommended_doctors'] = $doctors;
        $diagnosisData['recommended_labs'] = $labs;
        $diagnosisData['form'] = $form;
        return $diagnosisData;
    }

    /**
     * دریافت تاریخچه تشخیص‌های کاربر
     */
    public function history(Request $request): JsonResponse
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'احراز هویت لازم است'
            ], 401);
        }
        $cacheKeys = Cache::get("user_diagnosis_keys_{$request->user()->id}", []);
        $history = [];

        foreach ($cacheKeys as $key) {
            $data = Cache::get($key);
            if ($data) {
                $history[] = $data;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $history
        ]);
    }

    /**
     * دریافت دکترهای یک تخصص خاص
     */
    public function getDoctorsBySpecialty(Request $request, int $specialtyId): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:50',
            'min_rating' => 'nullable|numeric|min:0|max:5',
            'is_vip' => 'nullable|boolean',
        ]);

        $query = DB::table('doctor_info')
            ->join('users', 'doctor_info.user_id', '=', 'users.id')
            ->join('specialties', 'doctor_info.specialty_id', '=', 'specialties.id')
            ->where('doctor_info.specialty_id', $specialtyId)
            ->select(
                'doctor_info.id',
                'doctor_info.name',
                'doctor_info.image_url',
                'doctor_info.rating',
                'doctor_info.visit_price',
                'doctor_info.experience',
                'doctor_info.is_vip',
                'doctor_info.address',
                'doctor_info.phone',
                'specialties.name as specialty_name'
            );

        if (isset($validated['min_rating'])) {
            $query->where('doctor_info.rating', '>=', $validated['min_rating']);
        }

        if (isset($validated['is_vip'])) {
            $query->where('doctor_info.is_vip', $validated['is_vip']);
        }

        $doctors = $query
            ->orderByDesc('doctor_info.is_vip')
            ->orderByDesc('doctor_info.rating')
            ->limit($validated['limit'] ?? 10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $doctors
        ]);
    }

    /**
     * دریافت آزمایشگاه‌های مرتبط با تخصص
     */
    public function getLabsBySpecialty(Request $request, int $specialtyId): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:50',
            'min_rating' => 'nullable|numeric|min:0|max:5',
        ]);

        $query = DB::table('medical_centers')
            ->join('service_types', 'medical_centers.service_type_id', '=', 'service_types.id')
            ->where('service_types.name', 'آزمایشگاه')
            ->where(function ($q) use ($specialtyId) {
                $q->where('medical_centers.specialty_id', $specialtyId)
                    ->orWhereNull('medical_centers.specialty_id');
            })
            ->select(
                'medical_centers.id',
                'medical_centers.name',
                'medical_centers.address',
                'medical_centers.phone',
                'medical_centers.rating',
                'medical_centers.image_url'
            );

        if (isset($validated['min_rating'])) {
            $query->where('medical_centers.rating', '>=', $validated['min_rating']);
        }

        $labs = $query
            ->orderByDesc('medical_centers.rating')
            ->limit($validated['limit'] ?? 10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $labs
        ]);
    }
    public function getDoctorWithSchedule(Request $request, $doctorId)
    {
        // اعتبارسنجی ورودی
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date|date_format:Y-m-d',
            'end_date' => 'nullable|date|date_format:Y-m-d|after_or_equal:start_date',
            'days' => 'nullable|integer|min:1|max:30'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'داده‌های ورودی نامعتبر است',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // دریافت اطلاعات دکتر
            $doctor = DB::table('doctor_info')
                ->join('users', 'doctor_info.user_id', '=', 'users.id')
                ->join('specialties', 'doctor_info.specialty_id', '=', 'specialties.id')
                ->where('users.id', $doctorId)
                ->select(
                    'users.id',
                    'users.name',
                    'users.email',
                    'users.phone',
                    'doctor_info.specialty_id',
                    'specialties.name as specialty_name',
                    'doctor_info.visit_price',
                    'doctor_info.experience',
                    'doctor_info.address',
                    'doctor_info.rating',
                    'doctor_info.visit_count',
                    'doctor_info.image_url',
                    'doctor_info.is_vip',
                    'doctor_info.bio'
                )
                ->first();

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'دکتر مورد نظر یافت نشد'
                ], 404);
            }
            if (isset($doctor->image_url)) {
                $doctor->image_url = asset('storage/' . $doctor->image_url);
            }
            // محاسبه بازه زمانی
            $startDate = $request->input('start_date', now()->format('Y-m-d'));

            if ($request->has('end_date')) {
                $endDate = $request->input('end_date');
            } else {
                $days = $request->input('days', 7);
                $endDate = now()->addDays($days)->format('Y-m-d');
            }

            // دریافت وقت‌های آزاد
            $availableSlots = DB::table('appointment_slots')
                ->select(
                    'id',
                    'slot_date',
                    'start_time',
                    'end_time',
                    'status',
                    DB::raw("DATE_FORMAT(slot_date, '%Y-%m-%d') as date_formatted"),
                    DB::raw("TIME_FORMAT(start_time, '%H:%i') as start_formatted"),
                    DB::raw("TIME_FORMAT(end_time, '%H:%i') as end_formatted"),
                    DB::raw("CONCAT(DATE_FORMAT(slot_date, '%Y-%m-%d'), ' ', TIME_FORMAT(start_time, '%H:%i')) as datetime_full")
                )
                ->where('doctor_id', $doctorId)
                ->where('slot_date', '>=', $startDate)
                ->where('slot_date', '<=', $endDate)
                ->where('status', 'available')
                ->orderBy('slot_date')
                ->orderBy('start_time')
                ->get();

            // گروه‌بندی وقت‌ها بر اساس تاریخ
            $slotsByDate = $availableSlots->groupBy('date_formatted')->map(function ($slots) {
                return $slots->map(function ($slot) {
                    return [
                        'id' => $slot->id,
                        'start_time' => $slot->start_formatted,
                        'end_time' => $slot->end_formatted,
                        'datetime' => $slot->datetime_full,
                        'status' => $slot->status
                    ];
                })->values();
            });

            // آمار وقت‌های آزاد
            $stats = [
                'total_slots' => $availableSlots->count(),
                'available_days' => $slotsByDate->count(),
                'date_range' => [
                    'start' => $startDate,
                    'end' => $endDate
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'doctor' => [
                        'id' => $doctor->id,
                        'name' => $doctor->name,
                        'email' => $doctor->email,
                        'phone' => $doctor->phone,
                        'specialty_id' => $doctor->specialty_id,
                        'specialty_name' => $doctor->specialty_name,
                        'visit_price' => $doctor->visit_price,
                        'experience' => $doctor->experience,
                        'address' => $doctor->address,
                        'rating' => $doctor->rating,
                        'visit_count' => $doctor->visit_count,
                        'image_url' => $doctor->image_url,
                        'is_vip' => (bool) $doctor->is_vip,
                        'bio' => $doctor->bio
                    ],
                    'available_slots' => $slotsByDate,
                    'stats' => $stats
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت اطلاعات: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * دریافت وقت‌های آزاد یک دکتر در یک روز خاص
     */
    public function getDoctorSlotsByDate(Request $request, $doctorId)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date|date_format:Y-m-d'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'تاریخ نامعتبر است',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $date = $request->input('date');

            $slots = DB::table('appointment_slots')
                ->select(
                    'id',
                    'slot_date',
                    'start_time',
                    'end_time',
                    'status',
                    DB::raw("TIME_FORMAT(start_time, '%H:%i') as start_formatted"),
                    DB::raw("TIME_FORMAT(end_time, '%H:%i') as end_formatted")
                )
                ->where('doctor_id', $doctorId)
                ->where('slot_date', $date)
                ->where('status', 'available')
                ->orderBy('start_time')
                ->get();

            if ($slots->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'وقت آزادی برای این تاریخ یافت نشد'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'date' => $date,
                    'slots' => $slots->map(function ($slot) {
                        return [
                            'id' => $slot->id,
                            'start_time' => $slot->start_formatted,
                            'end_time' => $slot->end_formatted,
                            'status' => $slot->status
                        ];
                    })
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت وقت‌ها: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * لیست دکترها با تعداد وقت‌های آزاد
     */
    public function getDoctorsWithAvailability(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'specialty_id' => 'nullable|integer|exists:specialties,id',
            'start_date' => 'nullable|date|date_format:Y-m-d',
            'days' => 'nullable|integer|min:1|max:30'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $startDate = $request->input('start_date', now()->format('Y-m-d'));
            $days = $request->input('days', 7);
            $endDate = now()->addDays($days)->format('Y-m-d');

            $query = DB::table('doctor_info')
                ->join('users', 'doctor_info.user_id', '=', 'users.id')
                ->join('specialties', 'doctor_info.specialty_id', '=', 'specialties.id')
                ->leftJoin('appointment_slots', function($join) use ($startDate, $endDate) {
                    $join->on('users.id', '=', 'appointment_slots.doctor_id')
                        ->where('appointment_slots.status', 'available')
                        ->whereBetween('appointment_slots.slot_date', [$startDate, $endDate]);
                })
                ->select(
                    'users.id',
                    'users.name',
                    'doctor_info.specialty',
                    'specialties.name as specialty_name',
                    'doctor_info.visit_price',
                    'doctor_info.experience',
                    'doctor_info.rating',
                    'doctor_info.image_url',
                    'doctor_info.is_vip',
                    DB::raw('COUNT(appointment_slots.id) as available_slots_count')
                )
                ->where('users.role', 'doctor')
                ->groupBy(
                    'users.id',
                    'users.name',
                    'doctor_info.specialty',
                    'specialties.name',
                    'doctor_info.visit_price',
                    'doctor_info.experience',
                    'doctor_info.rating',
                    'doctor_info.image_url',
                    'doctor_info.is_vip'
                );

            if ($request->has('specialty_id')) {
                $query->where('doctor_info.specialty_id', $request->input('specialty_id'));
            }

            $doctors = $query->orderByDesc('doctor_info.is_vip')
                ->orderByDesc('available_slots_count')
                ->orderByDesc('doctor_info.rating')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'doctors' => $doctors,
                    'date_range' => [
                        'start' => $startDate,
                        'end' => $endDate
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت لیست دکترها: ' . $e->getMessage()
            ], 500);
        }
    }
    public function suggestKeywords(Request $request)
    {
        $query = $request->input('q');
        if (!$query) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $keywords = DB::table('keywords')
            ->where('word', 'LIKE', '%' . $query . '%')
            ->orderBy('search_volume', 'desc')
            ->limit(5)
            ->pluck('word');

        // آپدیت کردن search_volume در پس‌زمینه (اختیاری)
        DB::table('keywords')->where('word', $query)->increment('search_volume');

        return response()->json(['success' => true, 'data' => $keywords]);
    }

    // ۲. آپدیت متد لیست پزشکان
    public function getDoctorsListV0(Request $request)
    {
        $tomorrow = now()->addDay()->format('Y-m-d');
        $searchTerm = $request->input('query');

        $query = DB::table('users')
            ->join('doctor_info', 'users.id', '=', 'doctor_info.user_id')
            ->join('specialties', 'doctor_info.specialty_id', '=', 'specialties.id')
            ->leftJoin('doctor_subscriptions as ds', function ($join) {
                $join->on('users.id', '=', 'ds.doctor_id')
                    ->where('ds.status', 1)
                    ->whereNotNull('ds.expires_at')
                    ->where('ds.expires_at', '>', now());
            })
            ->leftJoin('doctor_plans as dp', 'ds.plan_id', '=', 'dp.id')
            ->leftJoin('appointment_slots', function($join) use ($tomorrow) {
                $join->on('users.id', '=', 'appointment_slots.doctor_id')
                    ->where('appointment_slots.status', 'available')
                    ->where('appointment_slots.slot_date', $tomorrow);
            })
            // 1. ابتدا فیلدهای اصلی را انتخاب کنید
            ->select(
                'users.id', 'users.name as firstName', 'users.gender',
                'specialties.name as specialty', 'doctor_info.visit_price',
                'doctor_info.experience', 'doctor_info.rating',
                'doctor_info.image_url as image', 'doctor_info.is_vip',
                'doctor_info.lat', 'doctor_info.lng', 'doctor_info.bio',
                'doctor_info.address', 'doctor_info.phone', 'doctor_info.visit_count',
                'doctor_info.appointments', 'doctor_info.medical_code as medicalCode',
                'doctor_info.rank', 'doctor_info.reviews', 'doctor_info.recommendation',
                DB::raw('COUNT(appointment_slots.id) as availability'),
                // اگر پلن فعال نداشته باشد، صفر برمی‌گردد
                DB::raw('COALESCE(MAX(dp.tier_level), 0) as plan_rank'),
                'doctor_info.city', 'doctor_info.province'
            );

        // 2. سپس بر اساس شرط، فیلدهای اضافی را اضافه (addSelect) کنید
        if (!empty($searchTerm)) {
            $query->leftJoin('doctor_keyword_subscriptions as dks', function($join) {
                $join->on('users.id', '=', 'dks.doctor_id')
                    ->where('dks.is_active', 1)
                    ->where('dks.expires_at', '>', now());
            })
                ->leftJoin('keywords', function($join) use ($searchTerm) {
                    $join->on('dks.keyword_id', '=', 'keywords.id')
                        ->where('keywords.word', 'LIKE', '%' . $searchTerm . '%');
                });

            // اصلاح جایگاه COALESCE و MAX
          //  $query->addSelect(DB::raw('COALESCE(MAX(dks.tier_level), 0) as search_rank'));

            $query->where(function($q) use ($searchTerm) {
                $q->where('users.name', 'LIKE', '%' . $searchTerm . '%')
                    ->orWhere('specialties.name', 'LIKE', '%' . $searchTerm . '%')
                    ->orWhere('keywords.word', 'LIKE', '%' . $searchTerm . '%');
            });
        } else {
          //  $query->addSelect(DB::raw('0 as search_rank'));
        }

        $query->where('users.role', 'doctor')
            ->whereNull('users.deleted_at')
            ->groupBy(
                'users.id', 'users.name', 'users.gender', 'specialties.name',
                'doctor_info.visit_price', 'doctor_info.experience', 'doctor_info.rating',
                'doctor_info.image_url', 'doctor_info.is_vip', 'doctor_info.lat',
                'doctor_info.lng', 'doctor_info.bio', 'doctor_info.address',
                'doctor_info.phone', 'doctor_info.visit_count', 'doctor_info.appointments',
                'doctor_info.medical_code', 'doctor_info.rank', 'doctor_info.reviews',
                'doctor_info.recommendation', 'doctor_info.city', 'doctor_info.province'
            );

        if (!empty($searchTerm)) {
            $query->orderBy('plan_rank', 'desc');
          //  $query->orderBy('search_rank', 'desc');
        }

        $query->orderBy('doctor_info.is_vip', 'desc')
            ->orderBy('doctor_info.rating', 'desc');

        $doctors = $query->get();

        $doctorIds = $doctors->pluck('id');
        $tags = DB::table('doctor_tags')
            ->join('tags', 'doctor_tags.tag_id', '=', 'tags.id')
            ->whereIn('doctor_tags.user_id', $doctorIds)
            ->select('doctor_tags.user_id', 'tags.name')
            ->get()
            ->groupBy('user_id');

        $doctors = $doctors->map(function($doctor) use ($tags) {
            $doctor->image = asset('storage/' . $doctor->image);
            $doctor->tags = isset($tags[$doctor->id]) ? $tags[$doctor->id]->pluck('name')->toArray() : [];
            return $doctor;
        });

        return response()->json([
            'success' => true,
            'data' => $doctors
        ]);
    }

    public function getDoctorsList(Request $request)
    {
        $now = now();
        $tomorrow = $now->copy()->addDay()->format('Y-m-d');
        $searchTerm = trim((string) $request->input('query', ''));
        $searcherIp = request()->ip();
        $userAgent = request()->userAgent(); // دریافت User Agent کاربر جهت ثبت در لاگ امنیتی
        $searcherId = auth()->check() ? auth()->id() : null;

        /*
         * مرحله اول: شناسایی کلمات کلیدی و دریافت "قیمت پایه نمایش" آن‌ها
         */
        $detectedKeywords = collect();
        $keywordPrices = []; // آرایه‌ای برای نگهداری قیمت هر کلمه [id => price]
        $keywordWords = [];
        if ($searchTerm !== '') {
            $detectedKeywords = DB::table('keywords')
                ->where('word', '=', $searchTerm)
                ->select('id', 'word', 'base_impression_tariff') // قیمت پایه استخراج می‌شود
                ->get();

            // ساخت یک آرایه کلید-مقدار برای دسترسی سریع به قیمت کلمات در زمان ثبت لاگ و کسر کیف پول
            $keywordPrices = $detectedKeywords->pluck('base_impression_tariff', 'id')->toArray();
            $keywordWords = $detectedKeywords->pluck('word', 'id')->toArray();
        }

        $detectedKeywordIds = $detectedKeywords->pluck('id')->all();

        $query = DB::table('users')
            ->join('doctor_info', 'users.id', '=', 'doctor_info.user_id')
            ->join('specialties', 'doctor_info.specialty_id', '=', 'specialties.id')

            // --- جدید: اتصال به جدول کیف پول برای دریافت موجودی در لحظه ---
            ->leftJoin('wallets', 'users.id', '=', 'wallets.user_id')

            ->leftJoin('doctor_subscriptions as ds', function ($join) use ($now) {
                $join->on('users.id', '=', 'ds.doctor_id')
                    ->where('ds.status', 1)
                    ->whereNotNull('ds.expires_at')
                    ->where('ds.expires_at', '>', $now);
            })
            ->leftJoin('doctor_plans as dp', 'ds.plan_id', '=', 'dp.id')
            ->leftJoin('appointment_slots', function ($join) use ($tomorrow) {
                $join->on('users.id', '=', 'appointment_slots.doctor_id')
                    ->where('appointment_slots.status', 'available')
                    ->where('appointment_slots.slot_date', $tomorrow);
            })
            ->select(
                'users.id',
                'users.name as firstName',
                'users.gender',
                'specialties.name as specialty',
                'doctor_info.visit_price',
                'doctor_info.experience',
                'doctor_info.rating',
                'doctor_info.image_url as image',
                'doctor_info.is_vip',
                'doctor_info.lat',
                'doctor_info.lng',
                'doctor_info.bio',
                'doctor_info.address',
                'doctor_info.phone',
                'doctor_info.visit_count',
                'doctor_info.appointments',
                'doctor_info.medical_code as medicalCode',
                'doctor_info.rank',
                'doctor_info.reviews',
                'doctor_info.recommendation',
                'doctor_info.city',
                'doctor_info.province',
                DB::raw('COUNT(DISTINCT appointment_slots.id) as availability'),
                // --- جدید: دریافت شناسه و موجودی کیف پول پزشک ---
                'wallets.id as wallet_id',
                'wallets.balance as wallet_balance'
            );

        if (!empty($detectedKeywordIds)) {
            $query
                ->leftJoin('doctor_keyword_subscriptions as dks', function ($join) use ($detectedKeywordIds, $now) {
                    $join->on('users.id', '=', 'dks.doctor_id')
                        ->where('dks.is_active', 1)
                        ->whereNotNull('dks.expires_at')
                        ->where('dks.expires_at', '>', $now)
                        ->whereIn('dks.keyword_id', $detectedKeywordIds);
                })
                ->leftJoin('keywords as matched_keywords', 'dks.keyword_id', '=', 'matched_keywords.id')
                ->addSelect(
                // استخراج Tier Level برای رتبه‌بندی
                    DB::raw("
                    COALESCE(MAX(CASE WHEN dks.keyword_id IS NOT NULL AND dp.tier_level IS NOT NULL THEN dp.tier_level ELSE 0 END), 0) AS search_rank
                "),
                    // استخراج Multiplier (ضریب) برای محاسبه هزینه
                    DB::raw("
                    COALESCE(MAX(CASE WHEN dks.keyword_id IS NOT NULL AND dp.multiplier IS NOT NULL THEN dp.multiplier ELSE 1 END), 1) AS plan_multiplier
                "),
                    // استخراج نام کلمات
                    DB::raw("
                    GROUP_CONCAT(DISTINCT matched_keywords.word ORDER BY matched_keywords.word SEPARATOR '|||') AS matched_keywords_raw
                "),
                    // استخراج ID کلمات (برای بررسی در لاگ و کیف پول ضروری است)
                    DB::raw("
                    GROUP_CONCAT(DISTINCT matched_keywords.id SEPARATOR ',') AS matched_keyword_ids
                ")
                );
        } else {
            $query->addSelect(
                DB::raw('0 AS search_rank'),
                DB::raw('1 AS plan_multiplier'),
                DB::raw("NULL AS matched_keywords_raw"),
                DB::raw("NULL AS matched_keyword_ids")
            );
        }

        if ($searchTerm !== '') {
            $query->where(function ($q) use ($searchTerm, $detectedKeywordIds) {
                $q->where('users.name', 'LIKE', '%' . $searchTerm . '%')
                    ->orWhere('specialties.name', 'LIKE', '%' . $searchTerm . '%');

                if (!empty($detectedKeywordIds)) {
                    $q->orWhereIn('dks.keyword_id', $detectedKeywordIds);
                }
            });
        }

        $query
            ->where('users.role', 'doctor')
            ->whereNull('users.deleted_at')
            ->groupBy(
                'users.id', 'users.name', 'users.gender', 'specialties.name',
                'doctor_info.visit_price', 'doctor_info.experience', 'doctor_info.rating',
                'doctor_info.image_url', 'doctor_info.is_vip', 'doctor_info.lat',
                'doctor_info.lng', 'doctor_info.bio', 'doctor_info.address',
                'doctor_info.phone', 'doctor_info.visit_count', 'doctor_info.appointments',
                'doctor_info.medical_code', 'doctor_info.rank', 'doctor_info.reviews',
                'doctor_info.recommendation', 'doctor_info.city', 'doctor_info.province',
                // --- اضافه شدن فیلدهای کیف پول به GroupBy ---
                'wallets.id', 'wallets.balance'
            );

        if (!empty($detectedKeywordIds)) {
            $query->orderByDesc('search_rank');
        }

        $query
            ->orderByDesc('doctor_info.is_vip')
            ->orderByDesc('doctor_info.rating');

        $doctors = $query->get();

        /*
         * -------------------------------------------------------------
         * محاسبه هزینه، کسر از کیف پول و ثبت لاگ (Real-time Billing)
         * با استفاده از Database Transaction برای حفظ یکپارچگی مالی
         * -------------------------------------------------------------
         */
        DB::transaction(function () use ($doctors, $keywordPrices,$keywordWords, $searcherIp, $userAgent, $searcherId) {
            $currentTime = now();

            foreach ($doctors as $doctor) {
                if ($doctor->search_rank > 0 && !empty($doctor->matched_keyword_ids)) {
                    $keywordIds = array_unique(explode(',', $doctor->matched_keyword_ids));

                    $multiplier = (float) ($doctor->plan_multiplier ?? 1);
                    $walletId = $doctor->wallet_id;
                    $currentBalance = (float) ($doctor->wallet_balance ?? 0); // موجودی لحظه‌ایِ لود شده

                    foreach ($keywordIds as $kId) {
                        $keywordWord = $keywordWords[$kId] ?? 'نامشخص'; // <-- اضافه شود
                        $basePrice = (float) ($keywordPrices[$kId] ?? 0);
                        $cost = $basePrice * $multiplier;

                        if ($cost > 0) {
                            // بررسی: آیا پزشک کیف پول دارد و آیا موجودی برای این کلمه کافی است؟
                            if ($walletId && $currentBalance >= $cost) {

                                // 1. کسر مبلغ از متغیر موجودی لوکال (برای کلمات بعدی همین پزشک در این حلقه)
                                $currentBalance -= $cost;

                                // 2. درج در لاگ نمایش و دریافت ID رکورد (insertGetId)
                                $logId = DB::table('keyword_consumption_logs')->insertGetId([
                                    'doctor_id'   => $doctor->id,
                                    'keyword_id'  => $kId,
                                    'ip_address'  => $searcherIp,
                                    'user_id'     => $searcherId,
                                    'action_type' => 'impression',
                                    'cost'        => $cost,
                                    'created_at'  => $currentTime,
                                ]);

                                // 3. درج در جدول تراکنش‌های کیف پول
                                DB::table('wallet_transactions')->insert([
                                    'wallet_id'     => $walletId,
                                    'type'          => 2, // 2 = Debit (برداشت)
                                    'amount'        => $cost,
                                    'balance_after' => $currentBalance,
                                    'subject_type'  => 7, // 7 = معرف نوع عملیات (keyword-impression)
                                    'subject_id'    => $logId, // لینک کردن تراکنش مالی به لاگ سیستم
                                    'description'   => "کسر هزینه نمایش برای کلمه کلیدی {$keywordWord} (شناسه: {$kId})",                                    'created_at'    => $currentTime,
                                    'ip_address'    => $searcherIp,
                                    'user_agent'    => $userAgent,
                                ]);

                                // 4. آپدیت موجودی کیف پول پزشک در دیتابیس
                                DB::table('wallets')
                                    ->where('id', $walletId)
                                    ->update([
                                        'balance'    => $currentBalance,
                                        'updated_at' => $currentTime
                                    ]);

                            } else {
                                // 5. موجودی کافی نیست (یا کاربر کیف پول ندارد) -> غیرفعال‌سازی هوشمند اشتراک
                                DB::table('doctor_keyword_subscriptions')
                                    ->where('doctor_id', $doctor->id)
                                    ->where('keyword_id', $kId)
                                    ->update([
                                        'is_active'  => 0,
                                        'updated_at' => $currentTime
                                    ]);
                            }
                        }
                    }
                }
            }
        });
        /* ------------------------------------------------------------- */

        // دریافت تگ‌ها برای کش کردن و Map نهایی
        $doctorIds = $doctors->pluck('id');

        $tags = DB::table('doctor_tags')
            ->join('tags', 'doctor_tags.tag_id', '=', 'tags.id')
            ->whereIn('doctor_tags.user_id', $doctorIds)
            ->select('doctor_tags.user_id', 'tags.name')
            ->get()
            ->groupBy('user_id');

        $doctors = $doctors->map(function ($doctor) use ($tags) {
            $doctor->image = $doctor->image ? asset('storage/' . ltrim($doctor->image, '/')) : null;
            $doctor->tags = isset($tags[$doctor->id]) ? $tags[$doctor->id]->pluck('name')->values()->toArray() : [];
            $doctor->matched_keywords = !empty($doctor->matched_keywords_raw) ? explode('|||', $doctor->matched_keywords_raw) : [];

            // پاک‌سازی فیلدهای اضافه و حساس (مالی) از خروجی نهایی API
            unset($doctor->matched_keywords_raw);
            unset($doctor->matched_keyword_ids);
            unset($doctor->plan_multiplier);
            unset($doctor->wallet_id);
            unset($doctor->wallet_balance);

            return $doctor;
        });

        // مخفی‌سازی قیمت کلمات از دید کاربر در کلاینت (افزایش امنیت)
        $cleanDetectedKeywords = $detectedKeywords->map(function($kw) {
            return [
                'id' => $kw->id,
                'word' => $kw->word
            ];
        });

        return response()->json([
            'success' => true,
            'detected_keywords' => $cleanDetectedKeywords,
            'query' => $searchTerm,
            'data' => $doctors,
        ]);
    }




    public function getDoctorWithScheduleV1(Request $request, $doctorId)
    {
        // اعتبارسنجی ورودی
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date|date_format:Y-m-d',
            'end_date' => 'nullable|date|date_format:Y-m-d|after_or_equal:start_date',
            'days' => 'nullable|integer|min:1|max:30'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'داده‌های ورودی نامعتبر است',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // دریافت اطلاعات دکتر
            $doctor = DB::table('doctor_info')
                ->join('users', 'doctor_info.user_id', '=', 'users.id')
                ->join('specialties', 'doctor_info.specialty_id', '=', 'specialties.id')
                ->where('users.id', $doctorId)
                ->select(
                    'users.id',
                    'users.name',
                    'users.email',
                    'users.phone',
                    'users.gender',
                    'doctor_info.specialty_id',
                    'specialties.name as specialty_name',
                    'doctor_info.visit_price',
                    'doctor_info.experience',
                    'doctor_info.address',
                    'doctor_info.rating',
                    'doctor_info.visit_count',
                    'doctor_info.image_url',
                    'doctor_info.is_vip',
                    'doctor_info.bio',
                    'doctor_info.lat',
                    'doctor_info.lng',
                    'doctor_info.appointments',
                    'doctor_info.medical_code',
                    'doctor_info.rank',
                    'doctor_info.reviews',
                    'doctor_info.recommendation',
                    'doctor_info.city',
                    'doctor_info.province'
                )
                ->first();

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'دکتر مورد نظر یافت نشد'
                ], 404);
            }
            if (!empty($doctor->image_url)) {
                $doctor->image_url = asset('storage/' . $doctor->image_url);
            }
            // دریافت تگ‌های دکتر
            $tags = DB::table('doctor_tags')
                ->join('tags', 'doctor_tags.tag_id', '=', 'tags.id')
                ->where('doctor_tags.user_id', $doctorId)
                ->pluck('tags.name')
                ->toArray();

            // محاسبه بازه زمانی
            $startDate = $request->input('start_date', now()->format('Y-m-d'));

            if ($request->has('end_date')) {
                $endDate = $request->input('end_date');
            } else {
                $days = $request->input('days', 7);
                $endDate = now()->addDays($days)->format('Y-m-d');
            }

            // دریافت وقت‌های آزاد
            $availableSlots = DB::table('appointment_slots')
                ->select(
                    'id',
                    'slot_date',
                    'start_time',
                    'end_time',
                    'status',
                    DB::raw("DATE_FORMAT(slot_date, '%Y-%m-%d') as date_formatted"),
                    DB::raw("TIME_FORMAT(start_time, '%H:%i') as start_formatted"),
                    DB::raw("TIME_FORMAT(end_time, '%H:%i') as end_formatted"),
                    DB::raw("CONCAT(DATE_FORMAT(slot_date, '%Y-%m-%d'), ' ', TIME_FORMAT(start_time, '%H:%i')) as datetime_full")
                )
                ->where('doctor_id', $doctorId)
                ->where('slot_date', '>=', $startDate)
                ->where('slot_date', '<=', $endDate)
                ->where('status', 'available')
                ->orderBy('slot_date')
                ->orderBy('start_time')
                ->get();

            // گروه‌بندی وقت‌ها بر اساس تاریخ
            $slotsByDate = $availableSlots->groupBy('date_formatted')->map(function ($slots) {
                return $slots->map(function ($slot) {
                    return [
                        'id' => $slot->id,
                        'start_time' => $slot->start_formatted,
                        'end_time' => $slot->end_formatted,
                        'datetime' => $slot->datetime_full,
                        'status' => $slot->status
                    ];
                })->values();
            });

            // آمار وقت‌های آزاد
            $stats = [
                'total_slots' => $availableSlots->count(),
                'available_days' => $slotsByDate->count(),
                'date_range' => [
                    'start' => $startDate,
                    'end' => $endDate
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'doctor' => [
                        'id' => $doctor->id,
                        'name' => $doctor->name,
                        'email' => $doctor->email,
                        'phone' => $doctor->phone,
                        'gender' => $doctor->gender,
                        'specialty_id' => $doctor->specialty_id,
                        'specialty_name' => $doctor->specialty_name,
                        'visit_price' => $doctor->visit_price,
                        'experience' => $doctor->experience,
                        'address' => $doctor->address,
                        'rating' => $doctor->rating,
                        'visit_count' => $doctor->visit_count,
                        'image_url' => $doctor->image_url,
                        'is_vip' => (bool) $doctor->is_vip,
                        'bio' => $doctor->bio,
                        'lat' => $doctor->lat,
                        'lng' => $doctor->lng,
                        'appointments' => $doctor->appointments,
                        'medical_code' => $doctor->medical_code,
                        'rank' => $doctor->rank,
                        'reviews' => $doctor->reviews,
                        'recommendation' => $doctor->recommendation,
                        'city' => $doctor->city,
                        'province' => $doctor->province,
                        'tags' => $tags
                    ],
                    'available_slots' => $slotsByDate,
                    'stats' => $stats
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت اطلاعات: ' . $e->getMessage()
            ], 500);
        }
    }





}
