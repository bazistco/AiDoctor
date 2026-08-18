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
            ->where(function ($query) use ($labId) {
                $query->where('ulr.lab_id', $labId)
                    ->orWhereNull('ulr.lab_id');
            })
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
                $item->tests = $tests->get($item->request_id, collect())->values()->all();
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
            ->where(function ($query) use ($labId) {
                $query->where('ulr.lab_id', $labId)
                    ->orWhereNull('ulr.lab_id');
            })
            ->select(
                'ulr.id',
                'ulr.lab_id',
                'ulr.address_id',
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
            return $this->error('درخواست یافت نشد.', 404);
        }

        if ($labRequest->address_id) {
            $address = DB::table('addresses')->where('id', $labRequest->address_id)->first();
            $labRequest->address = @$address->address;
        }
        // ۲. واکشی آزمایش‌ها و جوین با جدول نتایج (lab_request_results)
        $tests = DB::table('lab_request_test_packs as lrtp')
            ->join('labs_tests as lt', 'lt.id', '=', 'lrtp.lab_test_id')
            ->join('test_packs as tp', 'tp.id', '=', 'lt.test_pack_id')
            // اضافه شدن جوین برای دریافت فایل نتیجه:
            ->leftJoin('lab_request_results as lrr', 'lrr.lab_request_test_pack_id', '=', 'lrtp.id')
            ->where('lrtp.lab_request_id', $id)
            ->where('lt.lab_id', $labId)
            ->select(
                'lrtp.id as test_pack_id',
                'tp.name',
                'lt.price',
                'lrr.file_path as result_file' // خواندن مسیر فایل از جدول نتایج
            )
            ->get();

        $baseUrl = 'http://185.222.163.113:7000/'; // آدرس پایه برای فایل‌ها

        // پردازش آزمایش‌ها برای ساخت URL کامل نتیجه
        $processedTests = $tests->map(function ($test) use ($baseUrl) {
            $resultFileUrl = null;
            if (!empty($test->result_file)) {
                $resultFileUrl = str_starts_with($test->result_file, 'http')
                    ? $test->result_file
                    : $baseUrl . ltrim($test->result_file, '/');
            }

            return [
                'test_pack_id' => $test->test_pack_id,
                'name'         => $test->name,
                'price'        => $test->price,
                'result_file'  => $resultFileUrl,
            ];
        });

        // ۳. پردازش و آماده‌سازی داده‌ها نسخه
        $prescriptionDetails = $labRequest->prescription_details ? json_decode($labRequest->prescription_details) : null;
        $files = [];
        $prescriptionCode = null;
        $prescriptionType = 'none';

        // نوع ۱: دیجیتال | نوع ۲: فایل
        if ($labRequest->prescription_type_id == 2) {
            $prescriptionType = 'digital';
            $prescriptionCode = $prescriptionDetails->code ?? null;
        } elseif ($labRequest->prescription_type_id == 3) {
            $prescriptionType = 'file';
            if (isset($prescriptionDetails->files) && is_array($prescriptionDetails->files)) {
                $files = array_map(function($path) use ($baseUrl) {
                    if (str_starts_with($path, 'http')) return $path;
                    return $baseUrl . ltrim($path, '/');
                }, $prescriptionDetails->files);
            }
        }

        $data = [
            'id' => $labRequest->id,
            'address' => $labRequest->address,
            'code' => sprintf('LAB-%06d', $labRequest->id),
            'is_assigned' => !is_null($labRequest->lab_id),
            'status' => (int) $labRequest->status,
            'type' => $labRequest->visit_type == 0 ? 'home' : 'in-person',
            'scheduledDate' => $labRequest->created_at,
            'patientName' => $labRequest->patient_name,
            'patientPhone' => $labRequest->patient_phone,
            'prescriptionType' => $prescriptionType,
            'prescriptionCode' => $prescriptionCode,
            'prescriptionFiles' => $files,
            'tests' => $processedTests,
            'totalPrice' => $labRequest->total_price ?? $tests->sum('price'),
        ];

        return $this->success($data);
    }




    /**
     * پذیرش درخواستی که فاقد آزمایشگاه است (تخصیص به آزمایشگاه فعلی)
     */
    public function acceptRequest(Request $request, $id)
    {
        $labId = $request->lab_id; // دریافت شناسه آزمایشگاه از میدل‌ور

        DB::beginTransaction();
        try {
            // ۱. بررسی وجود درخواست
            $labRequest = DB::table('users_labs_requests')->where('id', $id)->first();

            if (!$labRequest) {
                return $this->error('درخواست یافت نشد.', 404);
            }

            // ۲. بررسی اینکه آیا درخواست قبلاً توسط آزمایشگاه دیگری گرفته شده است یا خیر
            if (!is_null($labRequest->lab_id) && $labRequest->lab_id != $labId) {
                return $this->error('این درخواست قبلاً توسط آزمایشگاه دیگری پذیرش شده است.', 403);
            }

            // ۳. اگر قبلاً توسط همین آزمایشگاه پذیرش شده باشد
            if ($labRequest->lab_id == $labId) {
                return $this->success(null, 'این درخواست قبلاً توسط شما پذیرش شده است و می‌توانید آزمایش‌ها را اختصاص دهید.');
            }

            // ۴. پذیرش درخواست (پر کردن lab_id)
            DB::table('users_labs_requests')->where('id', $id)->update([
                'lab_id' => $labId,
                'updated_at' => now(),
            ]);

            DB::commit();

            return $this->success(null, 'درخواست با موفقیت توسط شما پذیرش شد. اکنون می‌توانید آزمایش‌ها را تخصیص دهید.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('خطا در پذیرش درخواست: ' . $e->getMessage(), 500);
        }
    }


    public function uploadResult(Request $request, $id)
    {
        $request->validate([
            'test_pack_id' => 'required|integer',
            'file' => 'required|file|mimes:jpeg,png,jpg,gif,pdf|max:5120',
            'note' => 'nullable|string',
        ]);

        $file = $request->file('file');
        $testPackId = $request->input('test_pack_id');

        // ذخیره فایل جدید
        $path = $file->store('lab_result', 'public');
        $filePath = 'storage/' . $path;

        // بررسی وجود نتیجه از قبل
        $existingResult = DB::table('lab_request_results')
            ->where('lab_request_id', $id)
            ->where('lab_request_test_pack_id', $testPackId)
            ->first();

        if ($existingResult) {
            // (اختیاری) پاک کردن فایل قبلی از سرور برای جلوگیری از اشغال فضا
            // $oldPath = str_replace('storage/', '', $existingResult->file_path);
            // Storage::disk('public')->delete($oldPath);

            // آپدیت رکورد موجود
            DB::table('lab_request_results')
                ->where('id', $existingResult->id)
                ->update([
                    'file_path' => $filePath,
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'note' => $request->input('note'),
                    'updated_at' => now(),
                ]);

            $resultId = $existingResult->id;
            $message = 'نتیجه آزمایش با موفقیت بروزرسانی شد';
            $statusCode = 200;

        } else {
            // ایجاد رکورد جدید
            $resultId = DB::table('lab_request_results')->insertGetId([
                'lab_request_id' => $id,
                'lab_request_test_pack_id' => $testPackId,
                'file_path' => $filePath,
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'status' => 1,
                'note' => $request->input('note'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $message = 'نتیجه آزمایش با موفقیت آپلود شد';
            $statusCode = 201;
        }
        $updatedRows = DB::table('users_labs_requests')
            ->where('id', $id)
            ->update(['status' => 4]);

        return response()->json([
            'message' => $message,
            'result_id' => $resultId,
            'file_path' => $path
        ], $statusCode);
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
    // متد جدید برای دریافت لیست آزمایش‌های تعریف شده برای این آزمایشگاه (جهت نمایش در دراپ‌داون فرانت)
    public function getAvailableTests(Request $request)
    {
        $labId = $request->lab_id;
        $tests = DB::table('labs_tests as lt')
            ->join('test_packs as tp', 'tp.id', '=', 'lt.test_pack_id')
            ->where('lt.lab_id', $labId)
            ->select('lt.id as lab_test_id', 'tp.name', 'lt.price')
            ->get();

        return $this->success($tests);
    }

    // اصلاح متد ثبت آزمایش برای درخواست
    public function assignTestPacks(Request $request, $id)
    {
        $labId = $request->lab_id;

        $request->validate([
            'lab_test_ids' => 'required|array',
            'lab_test_ids.*' => 'integer|exists:labs_tests,id'
        ]);

        $labTestIds = $request->input('lab_test_ids');

        DB::beginTransaction();
        try {
            // ۱. بررسی وجود درخواست
            $labRequest = DB::table('users_labs_requests')
                ->where('id', $id)
                ->where('lab_id', $labId)
                ->first();

            if (!$labRequest) {
                return $this->error('درخواست یافت نشد.', 404);
            }

            // ۲. محاسبه مجموع هزینه آزمایش‌های انتخاب شده از جدول labs_tests
            $labTests = DB::table('labs_tests')
                ->whereIn('id', $labTestIds)
                ->where('lab_id', $labId)
                ->get();

            $totalPrice = $labTests->sum('price');

            // ۳. حذف تست‌های قبلی این درخواست (در صورت ویرایش مجدد)
            DB::table('lab_request_test_packs')->where('lab_request_id', $id)->delete();

            // ۴. آماده‌سازی دیتا برای ثبت در جدول واسط
            $insertData = [];
            $now = now();
            foreach ($labTests as $test) {
                $insertData[] = [
                    'lab_request_id' => $id,
                    'lab_test_id' => $test->id, // در ساختار شما این فیلد lab_test_id است
                    // اگر فیلد قیمت هنگام ثبت در دیتابیس دارید، اینجا اضافه کنید
                    // 'price_at_request' => $test->price,
                ];
            }

            // ۵. درج در جدول واسط
            if(count($insertData) > 0) {
                DB::table('lab_request_test_packs')->insert($insertData);
            }

            // ۶. آپدیت قیمت کل در جدول درخواست اصلی و تغییر وضعیت به 0 (در انتظار پرداخت)
            DB::table('users_labs_requests')->where('id', $id)->update([
                'total_price' => $totalPrice,
                'status' => 1, // 1: در انتظار پرداخت
                'updated_at' => $now,
            ]);

            DB::commit();

            return $this->success([
                'total_price' => $totalPrice
            ], 'آزمایش‌ها با موفقیت ثبت و هزینه به‌روزرسانی شد.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('خطا در ثبت اطلاعات: ' . $e->getMessage(), 500);
        }
    }
    // متد جدید برای حذف تمام آزمایش‌های تخصیص داده شده به یک درخواست
    public function unassignTestPacks(Request $request, $id)
    {
        $labId = $request->lab_id;

        DB::beginTransaction();
        try {
            // ۱. بررسی وجود درخواست
            $labRequest = DB::table('users_labs_requests')
                ->where('id', $id)
                ->where('lab_id', $labId)
                ->first();

            if (!$labRequest) {
                return $this->error('درخواست یافت نشد.', 404);
            }

            // ۲. حذف تست‌ها از جدول واسط
            DB::table('lab_request_test_packs')
                ->where('lab_request_id', $id)
                ->delete();

            // ۳. صفر کردن قیمت و بازگردانی وضعیت به 0
            DB::table('users_labs_requests')->where('id', $id)->update([
                'total_price' => 0,
                'status' => 0,
                'updated_at' => now(),
            ]);

            DB::commit();

            return $this->success([], 'آزمایش‌های قبلی با موفقیت حذف شدند و می‌توانید مجدداً انتخاب کنید.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('خطا در حذف اطلاعات: ' . $e->getMessage(), 500);
        }
    }



}
