<?php
// app/Http/Controllers/Owner/Labs/LabRequestController.php

namespace App\Http\Controllers\Api\Owner\Labs;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LabRequestController extends Controller
{
    use ApiResponse;


    public function getResults(Request $request)
    {
        $userId = auth()->id();

        $lab = DB::table('labs_info')->where('user_id', $userId)->first();
        if (!$lab) {
            return response()->json(['status' => false, 'message' => 'آزمایشگاه یافت نشد'], 404);
        }

        // واکشی نتایج برای درخواست‌های وضعیت 4
        $results = DB::table('lab_request_results as lrr')
            ->join('users_labs_requests as ulr', 'lrr.lab_request_id', '=', 'ulr.id')
            ->join('lab_request_test_packs as lrtp', 'lrr.lab_request_test_pack_id', '=', 'lrtp.id')
            ->leftJoin('labs_tests as lt', 'lrtp.lab_test_id', '=', 'lt.id')
            // جوین جدید برای دریافت نام دقیق آزمایش از جدول مرجع
            ->leftJoin('test_packs as tp', 'lt.test_pack_id', '=', 'tp.id')
            ->join('users as u', 'ulr.user_id', '=', 'u.id')
            ->where('ulr.lab_id', $lab->user_id)
            ->where('ulr.status', 4) // فقط درخواست‌های تکمیل شده
            ->select(
                'ulr.id as request_id',
                'u.name as patient_name',
                'lrtp.id as test_pack_id',
                'tp.name as test_name', // تغییر به tp.name
                'lrr.id as result_id',
                'lrr.file_path',
                'lrr.file_name',
                'lrr.mime_type',
                'lrr.status',
                'lrr.note',
                'lrr.created_at'
            )
            ->orderBy('lrr.created_at', 'desc')
            ->get();

        // گروه‌بندی بر اساس lab_request_test_pack_id
        $groupedResults = [];
        foreach ($results as $row) {
            $packId = $row->test_pack_id;

            if (!isset($groupedResults[$packId])) {
                $groupedResults[$packId] = [
                    'test_pack_id' => $packId,
                    'test_name' => $row->test_name ?? 'آزمایش نامشخص',
                    'request_id' => $row->request_id,
                    'request_code' =>  'LAB-REQ-'.$row->request_id,
                    'patient_name' => trim($row->patient_name ),
                    'uploaded_at' => $row->created_at,
                    'all_sent' => true,
                    'files' => []
                ];
            }

            if ($row->status == 0) {
                $groupedResults[$packId]['all_sent'] = false;
            }

            $groupedResults[$packId]['files'][] = [
                'result_id' => $row->result_id,
                'file_path' => $row->file_path,
                'file_name' => $row->file_name,
                'mime_type' => $row->mime_type,
                'status' => $row->status,
                'note' => $row->note,
            ];
        }

        return response()->json([
            'status' => true,
            'data' => array_values($groupedResults),
            'message' => 'نتایج با موفقیت دریافت شد'
        ]);
    }

    /**
     * لیست درخواست‌ها برای تقویم و زمان‌بندی (تاریخ میلادی)
     */
    public function schedule(Request $request)
    {
        $labId = $request->lab_id;

        // ۱. واکشی درخواست‌ها به همراه نام بیمار
        $requests = DB::table('users_labs_requests as ulr')
            ->join('users as u', 'u.id', '=', 'ulr.user_id')
            ->where('ulr.lab_id', $labId)
            ->select(
                'ulr.id',
                'ulr.status',
                'ulr.visit_type',
                'ulr.created_at as visit_date', // تاریخ میلادی
                'u.name as user_name'
            )
            ->get();

        if ($requests->isEmpty()) {
            return $this->success([]);
        }

        $requestIds = $requests->pluck('id')->toArray();

        // ۲. واکشی آزمایش‌های مربوط به این درخواست‌ها
        $tests = DB::table('lab_request_test_packs as lrtp')
            ->leftJoin('test_packs as tp', 'tp.id', '=', 'lrtp.lab_test_id')
            ->whereIn('lrtp.lab_request_id', $requestIds)
            ->select('lrtp.lab_request_id', 'tp.name')
            ->get()
            ->groupBy('lab_request_id');

        // ۳. مپ کردن داده‌ها (ارسال تاریخ به صورت میلادی)
        $mappedData = $requests->map(function ($req) use ($tests) {

            // استخراج بخش تاریخ (بدون ساعت) به فرمت YYYY-MM-DD
            $gregorianDate = \Carbon\Carbon::parse($req->visit_date)->format('Y-m-d');

            $requestTests = $tests->get($req->id, collect())->map(function($t) {
                return ['name' => $t->name];
            })->values()->all();

            return [
                'id'            => $req->id,
                'patientName'   => $req->user_name ?? 'بدون نام',
                'code'          => $req->id,
                'status'        => (int) $req->status,
                'visit_type'    => (int) $req->visit_type,
                // تاریخ میلادی ارسال می‌شود. فرانت‌اند باید این مقدار را به شمسی تبدیل کند
                'scheduledDate' => $gregorianDate,
                'tests'         => $requestTests,
            ];
        });

        return $this->success($mappedData);
    }

    /**
     * لیست تمام درخواست‌های آزمایشگاه
     */
    public function index(Request $request)
    {
        $labId = $request->lab_id;

        // ۱. کوئری اصلی: فقط واکشی درخواست‌ها، کاربر و نسخه (بدون آزمایش‌ها)
        $query = DB::table('users_labs_requests as ulr')
            ->join('users as u', 'u.id', '=', 'ulr.user_id')
            ->leftJoin('users_prescriptions as up', 'up.id', '=', 'ulr.user_prescription_id')
            ->where('ulr.lab_id', $labId)
            ->select(
                'ulr.id as request_id',
                'ulr.visit_type',
                'ulr.status as request_status',
                'ulr.total_price',
                'ulr.created_at as request_date',
                'u.id as user_id',
                'u.name as user_name',
                'u.phone as user_phone',
                'up.prescription_type_id',
                'up.details as prescription_details'
            );

        // فیلترها
        if (!is_null($request->query('visit_type'))) {
            $query->where('ulr.visit_type', $request->query('visit_type'));
        }
        if (!is_null($request->query('status'))) {
            $query->where('ulr.status', $request->query('status'));
        }
        if ($request->query('from_date')) {
            $query->where('ulr.created_at', '>=', $request->query('from_date'));
        }
        if ($request->query('to_date')) {
            $query->where('ulr.created_at', '<=', $request->query('to_date'));
        }

        // ۲. اعمال صفحه‌بندی روی درخواست‌های یکتا
        $paginator = $query->orderByDesc('ulr.id')->paginate($request->query('per_page', 15));

        // ۳. استخراج شناسه درخواست‌های موجود در این صفحه
        $requestIds = collect($paginator->items())->pluck('request_id')->toArray();

        if (!empty($requestIds)) {
            // ۴. واکشی آزمایش‌ها فقط برای درخواست‌های همین صفحه و گروه‌بندی بر اساس request_id
            $tests = DB::table('lab_request_test_packs as lrtp')
                ->leftJoin('test_packs as tp', 'tp.id', '=', 'lrtp.lab_test_id')
                ->leftJoin('labs_tests as lt', function ($join) use ($labId) {
                    $join->on('lt.test_pack_id', '=', 'tp.id')
                        ->where('lt.lab_id', '=', $labId);
                })
                ->whereIn('lrtp.lab_request_id', $requestIds)
                ->select(
                    'lrtp.lab_request_id',
                    'tp.id as test_pack_id',
                    'tp.name as test_name',
                    'lt.price as test_price'
                )
                ->get()
                ->groupBy('lab_request_id');

            // ۵. تزریق آزمایش‌ها به آبجکت درخواست
            foreach ($paginator->items() as $item) {
                // دیکد کردن فیلد details نسخه (برای اینکه به جای رشته، تبدیل به آبجکت/آرایه شود)
                if (is_string($item->prescription_details)) {
                    $item->prescription_details = json_decode($item->prescription_details);
                }

                // تخصیص آرایه آزمایش‌ها به درخواست (اگر آزمایشی نداشت آرایه خالی می‌دهد)
                $item->tests = $tests->get($item->request_id, [])->values()->all();
            }
        }

        return $this->paginated($paginator);
    }



    /**
     * جزئیات یک درخواست + لیست آزمایش‌های آن
     */
    /**
     * جزئیات یک درخواست + لیست آزمایش‌های آن
     */
    public function show(Request $request, int $id)
    {
        // دریافت lab_id که توسط میدل‌ور در request قرار گرفته است
        $labId = $request->lab_id;

        // ۱. واکشی اطلاعات اصلی درخواست، بیمار و نسخه
        $labRequest = DB::table('users_labs_requests as ulr')
            ->join('users as u', 'u.id', '=', 'ulr.user_id')
            ->leftJoin('users_prescriptions as up', 'up.id', '=', 'ulr.user_prescription_id')
            ->where('ulr.id', $id)
            ->where('ulr.lab_id', $labId)
            ->select(
                'ulr.id',
                'ulr.status',
                'ulr.visit_type',
                'ulr.created_at',
                'ulr.total_price',
                'u.name as patient_name',
                'u.phone as patient_phone',
                'up.prescription_type_id',
                'up.details as prescription_details'
            )
            ->first();

        if (!$labRequest) {
            // استفاده از تریت ApiResponse برای پاسخ‌دهی یکسان
            return $this->error('درخواست یافت نشد.', 404);
        }

        // ۲. واکشی آزمایش‌های مربوط به این درخواست
        // این کوئری بر اساس ساختار lab_request_test_packs.lab_test_id -> labs_tests.id نوشته شده
        $tests = DB::table('lab_request_test_packs as lrtp')
            ->join('labs_tests as lt', 'lt.id', '=', 'lrtp.lab_test_id')
            ->join('test_packs as tp', 'tp.id', '=', 'lt.test_pack_id')
            ->where('lrtp.lab_request_id', $id)
            // اطمینان از اینکه آیتم‌های آزمایش متعلق به همین آزمایشگاه هستند
            ->where('lt.lab_id', $labId)
            ->select('tp.name', 'lt.price')
            ->get();

        // ۳. پردازش و آماده‌سازی داده‌ها برای ارسال به فرانت‌اند
        $prescriptionDetails = $labRequest->prescription_details ? json_decode($labRequest->prescription_details) : null;
        $files = [];
        $prescriptionCode = null;
        $prescriptionType = 'none';

        // فرض می‌کنیم در جدول prescription_types شناسه 1 برای "دیجیتال" و 2 برای "فایل" است
        if ($labRequest->prescription_type_id == 1) {
            $prescriptionType = 'digital';
            $prescriptionCode = $prescriptionDetails->code ?? null;
        } elseif ($labRequest->prescription_type_id == 2) {
            $prescriptionType = 'file';
            // فایل‌ها ممکن است به صورت آرایه در فیلد details->files ذخیره شده باشند
            if (isset($prescriptionDetails->files) && is_array($prescriptionDetails->files)) {
                $files = $prescriptionDetails->files;
            }
        }

        $data = [
            'id' => $labRequest->id,
            'code' => sprintf('LAB-%06d', $labRequest->id), // ساخت یک کد خوانا برای نمایش
            'status' => (int) $labRequest->status, // 0:pending, 1:confirmed, 2:rejected
            'type' => $labRequest->visit_type == 0 ? 'home' : 'in-person', // 0: در منزل, 1: حضوری
            'scheduledDate' => $labRequest->created_at, // تاریخ میلادی ارسال می‌شود و فرانت آن را شمسی می‌کند
            'patientName' => $labRequest->patient_name,
            'patientPhone' => $labRequest->patient_phone,
            'prescriptionType' => $prescriptionType,
            'prescriptionCode' => $prescriptionCode,
            'prescriptionFiles' => $files,
            'tests' => $tests,
            'totalPrice' => $labRequest->total_price ?? $tests->sum('price'), // اولویت با قیمت کل ذخیره شده در درخواست
        ];

        // استفاده از تریت ApiResponse برای پاسخ‌دهی
        return $this->success($data);
    }


    /**
     * بروزرسانی وضعیت درخواست
     */
    public function updateStatus(Request $request, int $id)
    {
        $labId = $request->lab_id;

        $exists = DB::table('users_labs_requests')
            ->where('id', $id)
            ->where('lab_id', $labId)
            ->exists();

        if (!$exists) {
            return $this->error('درخواست یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('users_labs_requests')
            ->where('id', $id)
            ->where('lab_id', $labId)
            ->update(['status' => $request->input('status')]);

        return $this->success(null, 'وضعیت درخواست بروزرسانی شد');
    }

    /**
     * آمار کلی درخواست‌ها
     */
    public function stats(Request $request)
    {
        $labId = $request->lab_id;

        $total = DB::table('users_labs_requests')->where('lab_id', $labId)->count();
        $pending = DB::table('users_labs_requests')->where('lab_id', $labId)->where('status', 0)->count();
        $confirmed = DB::table('users_labs_requests')->where('lab_id', $labId)->where('status', 1)->count();
        $rejected = DB::table('users_labs_requests')->where('lab_id', $labId)->where('status', 2)->count();

        return $this->success([
            'total'     => $total,
            'pending'   => $pending,
            'confirmed' => $confirmed,
            'rejected'  => $rejected,
        ]);
    }
}
