<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\OrderService;
use App\Services\Payment\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage; // <--- این خط اضافه شد
use Illuminate\Support\Facades\Validator;

class LabController extends Controller
{

    public function getLabShifts($labId)
    {
        // خواندن تنظیمات ذخیره شده آزمایشگاه از ردیس (همان کلیدی که در پنل آزمایشگاه ذخیره کردیم)
        $redisKey = "lab_rules_config:{$labId}";
        $rules = Redis::get($redisKey);

        if (!$rules) {
            // اگر تنظیماتی یافت نشد، مقادیر پیش‌فرض برمی‌گردانیم (یا ارور 404)
            $defaultShifts = [
                1 => ['isActive' => true, 'start' => '08:00', 'end' => '12:00', 'capacity' => 20],
                2 => ['isActive' => true, 'start' => '12:00', 'end' => '18:00', 'capacity' => 20],
                3 => ['isActive' => false, 'start' => '18:00', 'end' => '22:00', 'capacity' => 10],
            ];
            return response()->json(['success' => true, 'data' => $defaultShifts]);
        }

        $decodedRules = json_decode($rules, true);
        $shifts = $decodedRules['shifts'] ?? [];

        return response()->json([
            'success' => true,
            'data' => $shifts
        ]);
    }

    public function getPrescriptionTypes()
    {
        $items = DB::table('prescription_types')
            ->where('status', 1)
            ->select('id', 'name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function getTestPacks()
    {
        $items = DB::table('test_packs')
            ->join('labs_tests', 'labs_tests.test_pack_id', '=', 'test_packs.id')
            ->join('labs_info', 'labs_info.user_id', '=', 'labs_tests.lab_id')
            ->where('test_packs.status', 1)
            ->where('labs_tests.status', 1)
            ->where('labs_info.status', 1)
            ->groupBy('test_packs.id', 'test_packs.name', 'test_packs.status')
            ->select(
                'test_packs.id',
                'test_packs.name',
                'test_packs.status',
                DB::raw('MIN(labs_tests.price) as min_price'),
                DB::raw('MAX(labs_tests.price) as max_price')
            )
            ->orderBy('test_packs.name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function searchCenters(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'test_pack_ids' => 'required|array|min:1',
            'test_pack_ids.*' => 'integer|exists:test_packs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $testPackIds = array_values(array_unique($request->test_pack_ids));

        $labs = DB::table('labs_tests')
            ->join('labs_info', 'labs_info.user_id', '=', 'labs_tests.lab_id')
            ->join('users', 'users.id', '=', 'labs_info.user_id')
            ->where('users.status', 1)
            ->where('labs_info.status', 1)
            ->where('labs_tests.status', 1)
            ->whereIn('labs_tests.test_pack_id', $testPackIds)
            ->groupBy(
                'labs_info.user_id',
                'users.name',
                'labs_info.address',
                'labs_info.lat',
                'labs_info.lng',
                'labs_info.image'
            )
            ->havingRaw('COUNT(DISTINCT labs_tests.test_pack_id) = ?', [count($testPackIds)])
            ->select(
                'labs_info.user_id as id',
                'users.name',
                'labs_info.address',
                'labs_info.lat',
                'labs_info.lng',
                'labs_info.image',
                DB::raw('SUM(labs_tests.price) as total_price')
            )
            ->orderBy('total_price')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $labs,
        ]);
    }

    private function isLabActive(int $labId): bool
    {
        return DB::table('users as u')
            ->join('labs_info as df', 'u.id', '=', 'df.user_id')
            ->where('u.status', 1)
            ->where('df.status', 1)
            ->where('u.id', $labId)
            ->exists();
    }

    /**
     * اعتبارسنجی شیفت، زمان مجاز و ظرفیت و تولید شماره صف
     */
    private function validateAndGetQueueNumber($labId, $appointmentDate, $shiftType)
    {
        $redisKey = "lab_rules_config:{$labId}";
        $rulesJson = \Illuminate\Support\Facades\Redis::get($redisKey);

        if (!$rulesJson) {
            throw new \RuntimeException('تنظیمات شیفت‌ها برای این آزمایشگاه یافت نشد.');
        }

        $rules = json_decode($rulesJson, true);

        // ۱. بررسی باز بودن آزمایشگاه در روز انتخابی (۰=یکشنبه تا ۶=شنبه)
        $dayOfWeek = \Carbon\Carbon::parse($appointmentDate)->dayOfWeek;
        $activeDays = $rules['active_days'] ?? [];
        if (!in_array($dayOfWeek, $activeDays)) {
            throw new \RuntimeException('آزمایشگاه در تاریخ انتخابی فعالیتی ندارد.');
        }

        // ۲. بررسی فعال بودن شیفت انتخابی
        $shiftConfig = $rules['shifts'][$shiftType] ?? null;
        if (!$shiftConfig || empty($shiftConfig['isActive'])) {
            throw new \RuntimeException('شیفت انتخابی در حال حاضر برای این آزمایشگاه غیرفعال است.');
        }

        // ۳. بررسی محدودیت زمانی (اگر تاریخ نوبت برای امروز باشد)
        if ($appointmentDate === now()->toDateString()) {
            // محاسبه ساعت پایان شیفت برای امروز
            $shiftEnd = \Carbon\Carbon::parse($appointmentDate . ' ' . $shiftConfig['end']);

            // بررسی اینکه حداقل ۱ ساعت تا پایان شیفت زمان باقی مانده باشد
            if (now()->greaterThanOrEqualTo($shiftEnd->copy()->subHour())) {
                throw new \RuntimeException('زمان مجاز برای ثبت درخواست در این شیفت پایان یافته است (حداقل باید ۱ ساعت به پایان شیفت مانده باشد).');
            }
        }

        // ۴. قفل کردن رکوردها و محاسبه شماره صف جدید
        $maxQueueNumber = DB::table('users_labs_requests')
            ->where('lab_id', $labId)
            ->where('appointment_date', $appointmentDate)
            ->where('shift_type', $shiftType)
            ->lockForUpdate() // استفاده از قفل برای جلوگیری از تداخل (Race Condition)
            ->max('daily_queue_number');

        $dailyQueueNumber = ($maxQueueNumber ?? 0) + 1;

        // ۵. بررسی ظرفیت باقیمانده شیفت
        $shiftCapacity = (int) ($shiftConfig['capacity'] ?? 0);
        if ($dailyQueueNumber > $shiftCapacity) {
            throw new \RuntimeException('ظرفیت پذیرش این شیفت تکمیل شده است، لطفاً شیفت یا روز دیگری را انتخاب کنید.');
        }

        return $dailyQueueNumber;
    }
    public function storeRequest(Request $request, OrderService $orderService, PaymentService $paymentService)
    {
        $validator = Validator::make($request->all(), [
            'request_type_id' => 'required|integer|exists:lab_request_types,id',
            'visit_type' => 'required|integer|in:0,1',
            'user_address_id' => 'required|integer|exists:addresses,id',

            // شیفت: ۱=صبح، ۲=ظهر/عصر، ۳=شب
            'shift_type' => 'required_if:request_type_id,1|nullable|integer|in:1,2,3',
            // تاریخ نوبت اختیاری (اگر از سمت فرانت نیاید، خودکار فردا ست می‌شود)
            'appointment_date' => 'nullable|date|after_or_equal:today',

            'lab_id' => 'required_if:request_type_id,1|nullable|integer',
            'test_pack_ids' => 'required_if:request_type_id,1|array|min:1',
            'test_pack_ids.*' => 'integer|exists:test_packs,id',

            'digital_code' => 'required_if:request_type_id,2|nullable|string|max:100',

            'files' => 'required_if:request_type_id,3|array|min:1',
            'files.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $requestTypeId = (int) $request->request_type_id;
        if ($requestTypeId == 1) {
            if (!$this->isLabActive($request->lab_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'آزمایشگاه مورد نظر در حال حاضر غیرفعال است و امکان رزرو نوبت وجود ندارد'
                ], 422);
            }
        }

        try {
            $user = $request->user();

            // سرویس‌ها را با use به داخل کلوژر تراکنش پاس می‌دهیم
            $result = DB::transaction(function () use ($request, $user, $requestTypeId, $orderService, $paymentService) {

                $prescriptionDetails = [
                    'code' => '',
                    'files' => [],
                ];

                if ($requestTypeId === 2) {
                    $prescriptionDetails['code'] = $request->digital_code;
                }

                if ($requestTypeId === 3 && $request->hasFile('files')) {
                    foreach ($request->file('files') as $file) {
                        $path = $file->store('prescriptions', 'local');
                        $prescriptionDetails['files'][] = $path;
                    }
                }

                $prescriptionId = DB::table('users_prescriptions')->insertGetId([
                    'user_id' => $user->id,
                    'prescription_type_id' => $requestTypeId,
                    'status' => 1,
                    'details' => json_encode($prescriptionDetails),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $labId = null;
                $totalPrice = 0;
                $paymentUrl = null;
                $appointmentDate = null;
                $shiftType = null;
                $dailyQueueNumber = null;

                if ($requestTypeId === 1) {
                    $labId = (int) $request->lab_id;
                    $testPackIds = array_values(array_unique($request->test_pack_ids));

                    $labTests = DB::table('labs_tests')
                        ->join('labs_info', 'labs_info.user_id', '=', 'labs_tests.lab_id')
                        ->where('labs_info.user_id', $labId)
                        ->where('labs_info.status', 1)
                        ->where('labs_tests.status', 1)
                        ->whereIn('labs_tests.test_pack_id', $testPackIds)
                        ->select('labs_tests.id', 'labs_tests.test_pack_id', 'labs_tests.price')
                        ->get();

                    if ($labTests->count() !== count($testPackIds)) {
                        throw new \RuntimeException('برخی تست‌های انتخابی در این آزمایشگاه موجود نیست.');
                    }

                    $totalPrice = (float) $labTests->sum('price');

                    // تعیین تاریخ نوبت
                    $appointmentDate = $request->filled('appointment_date')
                        ? $request->appointment_date
                        : now()->addDay()->toDateString();

                    // تعیین شیفت انتخابی
                    $shiftType = $request->filled('shift_type') ? (int) $request->shift_type : 1;

                    // ----------------------------------------------------------------------
                    // فراخوانی تابع مجزا جهت اعتبارسنجی شیفت و دریافت شماره صف
                    // ----------------------------------------------------------------------
                    $dailyQueueNumber = $this->validateAndGetQueueNumber($labId, $appointmentDate, $shiftType);
                }

                $status = ($requestTypeId === 1) ? 1 : 0;

                $labRequestId = DB::table('users_labs_requests')->insertGetId([
                    'address_id' => @$request->user_address_id,
                    'user_id' => $user->id,
                    'lab_id' => $labId,
                    'visit_type' => $request->visit_type,
                    'request_type_id' => $requestTypeId,
                    'user_prescription_id' => $prescriptionId,
                    'appointment_date' => $appointmentDate,
                    'shift_type' => $shiftType,
                    'daily_queue_number' => $dailyQueueNumber,
                    'status' => $status,
                    'total_price' => $totalPrice,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($requestTypeId === 1) {
                    foreach ($labTests as $labTest) {
                        DB::table('lab_request_test_packs')->insert([
                            'lab_request_id' => $labRequestId,
                            'lab_test_id' => $labTest->id,
                            'status' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    // ایجاد سفارش و لینک پرداخت
                    $orderResult = $orderService->createOrReuse(
                        userId: $user->id,
                        reasonId: 5,
                        reasonRef: $labRequestId,
                        amount: 15000,
                        description: "پرداخت فاکتور آزمایشگاه - درخواست #{$labRequestId}"
                    );

                    $callbackUrl = 'https://mediraai.com/api/pg/call_back';

                    $paymentResult = $paymentService->initiate(
                        orderId: $orderResult['order_id'],
                        userId: $user->id,
                        callbackUrl: $callbackUrl
                    );

                    $paymentUrl = $paymentResult['payment_url'];
                }

                return [
                    'request_id' => $labRequestId,
                    'prescription_id' => $prescriptionId,
                    'lab_id' => $labId,
                    'appointment_date' => $appointmentDate,
                    'shift_type' => $shiftType,
                    'daily_queue_number' => $dailyQueueNumber,
                    'total_price' => $totalPrice,
                    'status' => $status,
                    'payment_url' => $paymentUrl,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => ($requestTypeId == 1) ? 'در حال انتقال به درگاه پرداخت...' : 'درخواست با موفقیت ثبت شد.',
                'data' => $result,
            ], 201);

        } catch (\RuntimeException $e) {
            // خطاهای مربوط به منطق کسب‌وکار (ظرفیت، زمان، تعطیلی و ...)
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطای سیستمی در پردازش درخواست رخ داده است.',
                // 'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getUserRequests(Request $request)
    {
        $items = DB::table('users_labs_requests')
            ->leftJoin('users', 'users.id', '=', 'users_labs_requests.lab_id')
            ->join('lab_request_types', 'lab_request_types.id', '=', 'users_labs_requests.request_type_id')
            ->where('users_labs_requests.user_id', $request->user()->id)
            ->orderByDesc('users_labs_requests.id')
            ->select(
                'users_labs_requests.id',
                'users_labs_requests.lab_id',
                'users_labs_requests.visit_type',
                'users_labs_requests.request_type_id',
                'users_labs_requests.status',
                'users_labs_requests.total_price',
                'users_labs_requests.created_at',
                'users.name as lab_name',
                'lab_request_types.name as request_type_name'
            )
            ->get();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function getUserRequestDetail(Request $request, $id)
    {
        $item = DB::table('users_labs_requests')
            ->leftJoin('users', 'users.id', '=', 'users_labs_requests.lab_id')
            ->join('lab_request_types', 'lab_request_types.id', '=', 'users_labs_requests.request_type_id')
            ->join('users_prescriptions', 'users_prescriptions.id', '=', 'users_labs_requests.user_prescription_id')
            ->where('users_labs_requests.user_id', $request->user()->id)
            ->where('users_labs_requests.id', $id)
            ->select(
                'users_labs_requests.*',
                'users.name as lab_name',
                'lab_request_types.name as request_type_name',
                'users_prescriptions.details as prescription_details',
                'users_prescriptions.prescription_type_id',
                'users_prescriptions.id as prescription_id' // اضافه شد برای روت دانلود
            )
            ->first();

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'درخواست یافت نشد.',
            ], 404);
        }

        $prescriptionDetails = json_decode($item->prescription_details, true);

        // تبدیل مسیرهای داخلی به روت دانلود امن برای فرانت‌اند
        if (isset($prescriptionDetails['files']) && is_array($prescriptionDetails['files'])) {
            $secureFileUrls = [];
            foreach ($prescriptionDetails['files'] as $filePath) {
                // نام فایل را استخراج می‌کنیم
                $fileName = basename($filePath);
                // ساخت URL امن برای دانلود با استفاده از روت جدید
                $secureFileUrls[] ="https://api.mediraai.com/api/owner/lab/prescription-file/{$item->prescription_id}/prescriptions/{$fileName}";

            }
            $prescriptionDetails['files'] = $secureFileUrls;
        }

        $tests = DB::table('lab_request_test_packs')
            ->join('labs_tests', 'labs_tests.id', '=', 'lab_request_test_packs.lab_test_id')
            ->join('test_packs', 'test_packs.id', '=', 'labs_tests.test_pack_id')
            ->where('lab_request_test_packs.lab_request_id', $id)
            ->select(
                'lab_request_test_packs.id',
                'labs_tests.id as lab_test_id',
                'test_packs.id as test_pack_id',
                'test_packs.name as test_pack_name',
                'labs_tests.price'
            )
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'request' => $item,
                'prescription_details' => $prescriptionDetails,
                'tests' => $tests,
            ],
        ]);
    }

    // متد جدید برای دانلود امن فایل‌های نسخه
    public function downloadPrescriptionFile(Request $request, $id, $fileName)
    {
        // ۱. بررسی اینکه آیا این نسخه متعلق به کاربری است که درخواست داده
        $prescription = DB::table('users_prescriptions')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$prescription) {
            return response()->json(['success' => false, 'message' => 'شما دسترسی به این نسخه را ندارید یا نسخه یافت نشد.'], 403);
        }

        // ۲. اطمینان از اینکه فایلی که کاربر درخواست داده، دقیقاً در دیتابیس برای این نسخه ثبت شده
        $details = json_decode($prescription->details, true);
        $files = $details['files'] ?? [];
        $targetPath = 'prescriptions/' . $fileName;

        if (!in_array($targetPath, $files)) {
            return response()->json(['success' => false, 'message' => 'فایل غیرمجاز است یا یافت نشد.'], 404);
        }

        // ۳. بررسی وجود فایل در سرور
        if (!Storage::disk('local')->exists($targetPath)) {
            return response()->json(['success' => false, 'message' => 'فایل نسخه در سرور موجود نیست.'], 404);
        }

        // ۴. ارسال امن فایل
        $mimeType = Storage::disk('local')->mimeType($targetPath);
        return Storage::disk('local')->response($targetPath, $fileName, [
            'Content-Type' => $mimeType,
        ]);
    }
}
