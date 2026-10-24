<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class StaffLabRequestController extends Controller
{
    /**
     * لیست درخواست‌های ارجاع داده شده به این کارمند
     */
    public function index(Request $request): JsonResponse
    {
        $labId = (int) $request->attributes->get('center_id');
        $staffUserId = $request->user()->id; // شناسه کاربر (کارمند) جاری

        $query = DB::table('users_labs_requests as ulr')
            ->join('users as u', 'u.id', '=', 'ulr.user_id')
            ->leftJoin('users_prescriptions as up', 'up.id', '=', 'ulr.user_prescription_id')
            ->where('ulr.lab_id', $labId)
            ->where('ulr.staff_id', $staffUserId) // 👈 فقط درخواست‌های خودش
            ->select(
                'ulr.id as request_id',
                'ulr.visit_type',
                'ulr.status as request_status',
                DB::raw('ulr.total_price / 10 as total_price'),
                'ulr.created_at as request_date',
                'u.name as user_name',
                'u.phone as user_phone'
            );

        if ($request->filled('visit_type')) {
            $query->where('ulr.visit_type', $request->query('visit_type'));
        }
        if ($request->filled('status')) {
            $query->where('ulr.status', (int) $request->query('status'));
        }

        $perPage = (int) $request->query('per_page', 15);
        $paginator = $query->orderByDesc('ulr.id')->paginate($perPage);

        // واکشی پکیج‌های آزمایش
        $requestIds = collect($paginator->items())->pluck('request_id')->toArray();
        if (!empty($requestIds)) {
            $tests = DB::table('lab_request_test_packs as lrtp')
                ->leftJoin('test_packs as tp', 'tp.id', '=', 'lrtp.lab_test_id')
                ->whereIn('lrtp.lab_request_id', $requestIds)
                ->select('lrtp.lab_request_id', 'tp.id as test_pack_id', 'tp.name as test_name')
                ->get()
                ->groupBy('lab_request_id');

            foreach ($paginator->items() as $item) {
                $item->tests = $tests->get($item->request_id, collect())->values()->all();
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $paginator->items(),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * جزئیات درخواست همراه با آدرس، فایل نسخه و آزمایش‌ها
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $labId = (int) $request->attributes->get('center_id');
        $staffUserId = $request->user()->id;

        $item = DB::table('users_labs_requests as ulr')
            ->join('users as u', 'u.id', '=', 'ulr.user_id')
            ->leftJoin('users_prescriptions as up', 'up.id', '=', 'ulr.user_prescription_id')
            ->where('ulr.id', $id)
            ->where('ulr.lab_id', $labId)
            ->where('ulr.staff_id', $staffUserId) // 👈 امنیت: فقط درخواست خودش
            ->select(
                'ulr.id as request_id',
                'ulr.visit_type',
                'ulr.address_id',
                'ulr.status as request_status',
                DB::raw('ulr.total_price / 10 as total_price'),
                'ulr.created_at as request_date',
                'u.name as user_name',
                'u.phone as user_phone',
                'up.id as prescription_id',
                'up.prescription_type_id',
                'up.details as prescription_details'
            )
            ->first();

        if (!$item) {
            return response()->json(['success' => false, 'message' => 'درخواست یافت نشد یا ارجاع داده نشده است.'], 404);
        }

        // استخراج آدرس
        $item->address = null;
        if ($item->address_id) {
            $addressRow = DB::table('addresses')->where('id', $item->address_id)->first();
            $item->address = $addressRow ? $addressRow->address : null;
            $item->lat= $addressRow ? $addressRow->lat : null;
            $item->lng= $addressRow ? $addressRow->lng : null;
        }

        // پردازش نسخه
        if (is_string($item->prescription_details)) {
            $item->prescription_details = json_decode($item->prescription_details);
        }

        $prescriptionFiles = [];
        if ($item->prescription_type_id == 3 && isset($item->prescription_details->files)) {
            foreach ($item->prescription_details->files as $path) {
                if (str_starts_with($path, 'http')) {
                    $prescriptionFiles[] = $path;
                } else {
                    $fileName = basename($path);
                    // تولید لینک اختصاصی کارمند
                    $prescriptionFiles[] = "https://api.mediraai.com/api/staff/lab/prescription-file/{$item->prescription_id}/{$fileName}";
                }
            }
        }
        $item->prescription_files = $prescriptionFiles;

        // واکشی آزمایش‌ها و نتایج آنها
        $baseUrl = rtrim(config('app.url', url('/')), '/') . '/';
        $tests = DB::table('lab_request_test_packs as lrtp')
            ->leftJoin('test_packs as tp', 'tp.id', '=', 'lrtp.lab_test_id')
            ->leftJoin('lab_request_results as lrr', 'lrr.lab_request_test_pack_id', '=', 'lrtp.id')
            ->where('lrtp.lab_request_id', $id)
            ->select(
                'lrtp.id as lab_request_test_pack_id',
                'tp.id as test_pack_id',
                'tp.name as test_name',
                'lrr.id as result_id',
                'lrr.file_path as result_file'
            )
            ->get();

        $item->tests = $tests->map(function ($test) use ($baseUrl) {
            $resultUrl = null;
            if ($test->result_file && $test->result_id) {
                $resultUrl = "https://api.mediraai.com/api/staff/lab/results/{$test->result_id}/download";
            }
            return [
                'lab_request_test_pack_id' => $test->lab_request_test_pack_id, // برای آپلود نتیجه نیاز است
                'test_pack_id' => $test->test_pack_id,
                'name'         => $test->test_name,
                'result_url'   => $resultUrl,
            ];
        });

        return response()->json(['success' => true, 'data' => $item], 200);
    }

    /**
     * تغییر وضعیت درخواست توسط کارمند
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $labId = (int) $request->attributes->get('center_id');
        $staffUserId = $request->user()->id;

        $validated = $request->validate(['status' => 'required|integer|in:2,3,4,5,6']);

        $exists = DB::table('users_labs_requests')
            ->where('id', $id)->where('lab_id', $labId)->where('staff_id', $staffUserId)->exists();

        if (!$exists) {
            return response()->json(['success' => false, 'message' => 'درخواست یافت نشد.'], 404);
        }

        DB::table('users_labs_requests')->where('id', $id)->update([
            'status' => $validated['status'],
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'وضعیت بروزرسانی شد.'], 200);
    }

    /**
     * آپلود نتیجه آزمایش توسط کارمند
     */
    public function uploadResult(Request $request, $id)
    {
        $labId = (int) $request->attributes->get('center_id');
        $staffUserId = $request->user()->id;

        $request->validate([
            'lab_request_test_pack_id' => 'required|integer', // شناسه کلید اصلی جدول واسط
            'file' => 'required|file|max:5120',
            'note' => 'nullable|string',
        ]);

        // اطمینان از دسترسی کارمند به این درخواست
        $hasAccess = DB::table('users_labs_requests')
            ->where('id', $id)->where('lab_id', $labId)->where('staff_id', $staffUserId)->exists();

        if (!$hasAccess) {
            return response()->json(['success' => false, 'message' => 'دسترسی مجاز نیست.'], 403);
        }

        $file = $request->file('file');
        $path = $file->store('lab_results', 'local');

        $existingResult = DB::table('lab_request_results')
            ->where('lab_request_id', $id)
            ->where('lab_request_test_pack_id', $request->lab_request_test_pack_id)
            ->first();

        if ($existingResult) {
            if (Storage::disk('local')->exists($existingResult->file_path)) {
                Storage::disk('local')->delete($existingResult->file_path);
            }
            DB::table('lab_request_results')->where('id', $existingResult->id)->update([
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'note'      => $request->note,
                'updated_at'=> now(),
            ]);
        } else {
            DB::table('lab_request_results')->insert([
                'lab_request_id' => $id,
                'lab_request_test_pack_id' => $request->lab_request_test_pack_id,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'status'    => 1,
                'note'      => $request->note,
                'created_at'=> now(),
                'updated_at'=> now(),
            ]);
        }

        // آپدیت وضعیت درخواست به 4 (تکمیل شده / آماده تحویل)
        DB::table('users_labs_requests')->where('id', $id)->update(['status' => 4, 'updated_at' => now()]);

        return response()->json(['success' => true, 'message' => 'نتیجه با موفقیت آپلود شد.'], 200);
    }

    /**
     * دانلود فایل نتیجه توسط کارمند
     */
    public function downloadResultFile(Request $request, $resultId)
    {
        $labId = (int) $request->attributes->get('center_id');
        $staffUserId = $request->user()->id;

        $result = DB::table('lab_request_results as lrr')
            ->join('users_labs_requests as ulr', 'lrr.lab_request_id', '=', 'ulr.id')
            ->where('lrr.id', $resultId)
            ->where('ulr.lab_id', $labId)
            ->where('ulr.staff_id', $staffUserId) // دسترسی محدود
            ->select('lrr.file_path', 'lrr.file_name', 'lrr.mime_type')
            ->first();

        if (!$result || !Storage::disk('local')->exists($result->file_path)) {
            return response()->json(['success' => false, 'message' => 'فایل یافت نشد.'], 404);
        }

        return Storage::disk('local')->response($result->file_path, $result->file_name);
    }

    /**
     * دانلود امن فایل نسخه بیمار برای کارمند
     */
    public function downloadPatientPrescription(Request $request, $prescriptionId, $fileName)
    {
        $labId = (int) $request->attributes->get('center_id');
        $staffUserId = $request->user()->id;

        $hasAccess = DB::table('users_labs_requests')
            ->where('user_prescription_id', $prescriptionId)
            ->where('lab_id', $labId)
            ->where('staff_id', $staffUserId)
            ->exists();

        if (!$hasAccess) {
            return response()->json(['success' => false, 'message' => 'شما دسترسی به این نسخه را ندارید.'], 403);
        }

        $pureFileName = basename($fileName);
        $targetPath = 'prescriptions/' . $pureFileName;

        if (!Storage::disk('local')->exists($targetPath)) {
            return response()->json(['success' => false, 'message' => 'فایل نسخه در سرور موجود نیست.'], 404);
        }

        $mimeType = Storage::disk('local')->mimeType($targetPath);
        return Storage::disk('local')->response($targetPath, $pureFileName, ['Content-Type' => $mimeType]);
    }


    public function schedule(Request $request)
    {
        // دریافت شناسه کارمند از توکن احراز هویت
        $staffId = $request->user()->id;

        // واکشی درخواست‌های ارجاع‌شده به این کارمند از تاریخ امروز به بعد (وضعیت ۲ = پرداخت شده/تایید شده)
        $requests = DB::table('users_labs_requests as ulr')
            ->join('users as u', 'ulr.user_id', '=', 'u.id')
            ->leftJoin('addresses as ua', 'ulr.address_id', '=', 'ua.id')
            ->where('ulr.staff_id', $staffId)
            ->whereDate('ulr.appointment_date', '>=', now()->toDateString())
            ->whereIn('ulr.status', [1, 2, 3]) // 1: جدید، 2: تایید شده، 3: در حال انجام
            ->select(
                'ulr.id',
                'ulr.lab_id',
                'ulr.visit_type',
                'ulr.appointment_date',
                'ulr.shift_type',
                'ulr.daily_queue_number',
                'ulr.status',
                'u.name as patient_name',
                'ua.address'
            )
            ->orderBy('ulr.appointment_date', 'asc')
            ->orderBy('ulr.shift_type', 'asc')
            ->get();

        // کش موقت تنظیمات آزمایشگاه‌ها در حلقه برای جلوگیری از کوئری تکراری به Redis
        $labRulesCache = [];

        $mappedRequests = $requests->map(function ($req) use (&$labRulesCache) {
            $labId = $req->lab_id;

            // خواندن قوانین شیفت آزمایشگاه از ردیس (بر اساس متد saveRules ادمین)
            if (!isset($labRulesCache[$labId])) {
                $redisData = Redis::get("lab_rules_config:{$labId}");
                $labRulesCache[$labId] = $redisData ? json_decode($redisData, true) : null;
            }

            $rules = $labRulesCache[$labId];
            $shiftType = $req->shift_type; // 1, 2, or 3

            // تعیین زمان شیفت
            $timeString = 'نامشخص';
            if ($rules && isset($rules['shifts'][$shiftType])) {
                $shiftInfo = $rules['shifts'][$shiftType];
                if (isset($shiftInfo['start']) && isset($shiftInfo['end'])) {
                    $timeString = $shiftInfo['start'] . ' الی ' . $shiftInfo['end'];
                }
            } else {
                // مقادیر جایگزین در صورتی که تنظیمات در ردیس موجود نباشد
                $fallbacks = [
                    1 => 'شیفت صبح',
                    2 => 'شیفت ظهر/عصر',
                    3 => 'شیفت شب'
                ];
                $timeString = $fallbacks[$shiftType] ?? 'نامشخص';
            }

            // اضافه کردن شماره نوبت صف (daily_queue_number) به رشته زمانی در صورت وجود
            if (!is_null($req->daily_queue_number)) {
                $timeString .= " (نوبت: {$req->daily_queue_number})";
            }

            // نگاشت وضعیت عددی دیتابیس به رشته‌های فرانت‌اند
            $statusMap = [
                1 => 'جدید',          // new / ثبت شده
                2 => 'تایید شده',     // confirmed / تایید شده توسط آزمایشگاه
                3 => 'در حال نمونه‌گیری' // sampling / در حال انجام / نمونه‌گیری
            ];

            return [
                'id' => $req->id,
                'patientName' => $req->patient_name,
                'date' => verta($req->appointment_date)->format('Y/m/d'),
                'time' => $timeString,
                'type' => $req->visit_type == 1 ? 'home' : 'lab', // 1: منزل, 0: حضوری
                'address' => $req->address,
                'status' => $statusMap[$req->status] ?? 'new',
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $mappedRequests
        ]);
    }

}
