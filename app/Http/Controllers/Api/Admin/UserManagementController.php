<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class UserManagementController extends Controller
{
    private function baseQuery()
    {
        return DB::table('users as u')
            ->join('cities as c', 'c.id', '=', 'u.city_id')
            ->join('provinces as p', 'p.id', '=', 'u.province_id')
            ->leftJoin('doctor_info as di', 'di.user_id', '=', 'u.id')
            ->select(
                'u.id', 'u.name', 'u.email', 'u.phone',
                'u.role', 'u.status', 'u.is_verify', 'u.avatar',
                'u.created_at', 'u.updated_at',
                'c.id as city', 'p.id as province',
                // فیلدهای مربوط به پزشک
                'di.document_status', 'di.document_rejection_reason',
                'di.national_code', 'di.is_national_code_verified',
                'di.medical_code',
                'di.national_card_image', 'di.medical_council_card_image', 'di.clinic_license_image'
            );
    }
    private function formatUser(object $user): array
    {
        // تابع کمکی برای تولید لینک دانلود ایمن برای ادمین
        // توجه: می‌توانید یک روت اختصاصی برای دانلود ادمین بسازید یا از Storage::url استفاده کنید.
        $getDocUrl = fn($path) => $path ? secure_url('/api/admin/documents/download?path=' . urlencode($path)) : null;

        return [
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'phone'      => $user->phone,
            'role'       => $user->role,
            'type'       => $user->role,
            'status'     => $user->status,
            'is_verify'  => (bool) $user->is_verify,
            'isVerified' => (bool) $user->is_verify,
            'avatar'     => $user->avatar ?? null,
            'city'       => $user->city,
            'province'   => $user->province,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,

            // اطلاعات احراز و مدارک پزشک
            'doctor_info' => $user->role === 'doctor' ? [
                'national_code'             => $user->national_code,
                'is_national_code_verified' => (bool) $user->is_national_code_verified,
                'medical_code'              => $user->medical_code,
                'document_status'           => $user->document_status ?? 'not_uploaded',
                'document_rejection_reason' => $user->document_rejection_reason,
                'documents' => [
                    'national_card'        => $getDocUrl($user->national_card_image),
                    'medical_council_card' => $getDocUrl($user->medical_council_card_image),
                    'clinic_license'       => $getDocUrl($user->clinic_license_image),
                ]
            ] : null,
        ];
    }
    public function pendingDoctors(Request $request)
    {

        $perPage = (int) $request->input('per_page', 15);

        // ساخت کوئری بر پایه متد پایه پروژه
        $query = $this->baseQuery()
            // اطمینان از اینکه فقط کاربران با نقش پزشک دریافت شوند (در صورت نیاز)
            ->where('u.role', 'doctor')
            ->where(function ($q) {
                // شرط ۱: کدملی تایید نشده باشد (0 یا NULL)
                $q->where(function ($subQ) {
                    $subQ->where('di.is_national_code_verified', 0)
                        ->orWhereNull('di.is_national_code_verified');
                });

                // شرط ۲: مدارک آپلود نشده باشد (0 یا NULL)
                $q->where(function ($subQ) {
                    $subQ->where('di.has_uploaded_documents', 0)
                        ->orWhereNull('di.has_uploaded_documents');
                });
            });

        // در صورت ارسال عبارت جستجو (نام، شماره تماس، کدملی)
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('u.name', 'like', "%{$search}%")
                    ->orWhere('u.phone', 'like', "%{$search}%")
                    ->orWhere('di.national_code', 'like', "%{$search}%");
            });
        }

        // صفحه‌بندی نتایج
        $paginated = $query->orderBy('u.id', 'desc')->paginate($perPage);

        // تبدیل و فرمت‌بندی رکوردهای هر صفحه با formatUser
        $formattedData = collect($paginated->items())->map(function ($doctor) {
            return $this->formatUser($doctor);
        });

        return response()->json([
            'success' => true,
            'data'    => $formattedData,
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ]
        ]);
    }
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'phone' => 'required|string|size:11|unique:users,phone|regex:/^09[0-9]{9}$/',
            'province' => 'required|string|max:100',
            'city' => 'required|string|max:100',
            'status' => 'required|in:active,inactive,blocked',
            'type' => 'required|in:patient,doctor,pharmacy,lab,nurse',
            'details' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $province = DB::table('provinces')->where('name', $request->province)->first();
            $city = DB::table('cities')
                ->where('name', $request->city)
                ->where('province_id', $province->id ?? null)
                ->first();

            if (!$province || !$city) {
                return response()->json([
                    'success' => false,
                    'message' => 'استان یا شهر معتبر نیست.'
                ], 400);
            }

            $statusMap = [
                'active' => 1,
                'inactive' => 0,
                'blocked' => 2,
            ];

            $roleMap = [
                'patient' => 'patient',
                'doctor' => 'doctor',
                'pharmacy' => 'pharmacy',
                'lab' => 'lab',
                'nurse' => 'medical_center', // اگر رول جدا داری اصلاحش کن
            ];

            $fullName = trim($request->firstName . ' ' . $request->lastName);

            $userId = DB::table('users')->insertGetId([
                'name' => $fullName,
                'phone' => $request->phone,
                'password' => Hash::make($request->phone),
                'role' => $roleMap[$request->type],
                'status' => $statusMap[$request->status],
                'is_verify' => 0,
                'province_id' => $province->id,
                'city_id' => $city->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // ساخت کیف پول برای همه کاربران
            DB::table('wallets')->insert([
                'user_id' => $userId,
                'balance' => 0,
                'version' => 0,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $details = $request->details ?? [];

            switch ($request->type) {
                case 'doctor':
                    $this->createDoctorInfo(
                        $userId,
                        $fullName,
                        $details,
                        $request->province,
                        $request->city
                    );
                    break;

                case 'lab':
                    $this->createLabInfo($userId, $fullName, $details);
                    break;

                case 'pharmacy':
                    $this->createMedicalCenterInfo($userId, $fullName, $details);
                    break;

                case 'nurse':
                    $this->createMedicalCenterInfo($userId, $fullName, $details);
                    break;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'کاربر با موفقیت ایجاد شد.',
                'data' => [
                    'id' => $userId,
                    'phone' => $request->phone,
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'خطا در ایجاد کاربر: ' . $e->getMessage()
            ], 500);
        }
    }

    private function createDoctorInfo($userId, $name, $details, $province, $city)
    {
        $specialtyId = $details['specialty_id'] ?? null;

        if (!$specialtyId) {
            throw new \Exception('specialty_id برای دکتر الزامی است.');
        }

        DB::table('doctor_info')->insert([
            'user_id' => $userId,
            'name' => $name,
            'bio' => $details['bio'] ?? null,
            'specialty_id' => $specialtyId,
            'visit_price' => $details['visit_price'] ?? 500000,
            'experience' => $details['experience'] ?? null,
            'address' => $details['address'] ?? null,
            'phone' => $details['phone'] ?? null,
            'medical_code' => $details['medical_code'] ?? null,
            'rank' => $details['rank'] ?? null,
            'image_url' => $details['image_url'] ?? 'https://www.tarhdokan.com/wp-content/uploads/2020/07/doctor-1-1.jpg',
            'lat' => $details['lat'] ?? null,
            'lng' => $details['lng'] ?? null,
            'province' => $province,
            'city' => $city,
            'is_vip' => 0,
            'rating' => 0.0,
            'visit_count' => 0,
            'appointments' => 0,
            'reviews' => 0,
            'recommendation' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLabInfo($userId, $name, $details)
    {
        $slug = Str::slug($name . '-' . $userId);

        DB::table('labs_info')->insert([
            'user_id' => $userId,
            'name' => $name,
            'slug' => $slug,
            'license_number' => $details['license_number'] ?? null,
            'technical_manager' => $details['technical_manager'] ?? null,
            'work_hours' => $details['work_hours'] ?? null,
            'min_order_amount' => $details['min_order_amount'] ?? 0,
            'status' => 1,
            'address' => $details['address'] ?? null,
            'lat' => $details['lat'] ?? null,
            'lng' => $details['lng'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createMedicalCenterInfo($userId, $name, $details)
    {
        $slug = Str::slug($name . '-' . $userId);

        DB::table('medical_centers_info')->insert([
            'user_id' => $userId,
            'name' => $name,
            'slug' => $slug,
            'status' => 1,
            'image' => $details['image'] ?? null,
            'lat' => $details['lat'] ?? null,
            'lng' => $details['lng'] ?? null,
            'address' => $details['address'] ?? null,
            'coverage_description' => $details['coverage_description'] ?? null,
            'coverage_radius' => $details['coverage_radius'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
