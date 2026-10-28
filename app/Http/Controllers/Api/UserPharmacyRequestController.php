<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinancialService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class UserPharmacyRequestController extends Controller
{
    protected $financialService;

    public function __construct(FinancialService $financialService)
    {
        $this->financialService = $financialService;
    }

    /**
     * لغو درخواست داروخانه توسط کاربر
     */
    public function cancelRequest($id, Request $request)
    {
        $userId = $request->user()->id;

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$pharmacyRequest) {
            return response()->json([
                'success' => false,
                'message' => 'درخواست یافت نشد یا متعلق به شما نیست.'
            ], 404);
        }

        if (!in_array((int)$pharmacyRequest->status, [0, 1])) {
            return response()->json([
                'success' => false,
                'message' => 'این درخواست در مرحله‌ای است که دیگر امکان لغو آن توسط شما وجود ندارد.'
            ], 422);
        }

        DB::beginTransaction();
        try {
            DB::table('users_pharmacy_requests')
                ->where('id', $id)
                ->update([
                    'status' => 7,
                    'updated_at' => now(),
                ]);

            if ((int)$pharmacyRequest->status === 1) {
                DB::table('orders')
                    ->where('user_id', $userId)
                    ->where('reason_id', 6)
                    ->where('reason_ref', $id)
                    ->where('status', 1)
                    ->update([
                        'status' => 3,
                        'cancelled_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'درخواست داروخانه با موفقیت لغو شد.'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Pharmacy request cancellation failed', [
                'request_id' => $id,
                'user_id'    => $userId,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در لغو درخواست. لطفاً دوباره تلاش کنید.'
            ], 500);
        }
    }

    /**
     * نمایش جزئیات درخواست داروخانه به همراه فاکتور و اطلاعات تحویل
     */
    public function show($id)
    {
        $userId = auth()->id();

        $request = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$request) {
            return response()->json(['success' => false, 'message' => 'درخواست یافت نشد'], 404);
        }

        if ($request->status < 1) {
            return response()->json([
                'success' => false,
                'message' => 'این درخواست هنوز تأیید نشده و فاکتوری ندارد',
            ], 400);
        }

        $items = DB::table('user_pharmacy_request_medicines as uprm')
            ->join('pharmacy_medicines as pm', 'uprm.pharmacy_medicine_id', '=', 'pm.id')
            ->join('medicines as m', 'pm.medicine_id', '=', 'm.id')
            ->where('uprm.user_pharmacy_request_id', $id)
            ->select(
                'uprm.id',
                'm.name as medicine_name',
                'uprm.quantity',
                DB::raw('uprm.price / 10 as unit_price'),
                DB::raw('(uprm.quantity * uprm.price) / 10 as total_price'),
                'pm.unit'
            )
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $request->id,
                'status' => $request->status,
                'status_label' => $this->statusLabel($request->status),
                'total_price' => $request->total_price/10,
                'created_at' => $request->created_at,
                'pharmacy_name' => DB::table('pharmacies_info')->where('user_id', $request->pharmacy_id)->value('name'),
                'medicines' => $items,
            ],
        ]);
    }

    /**
     * پرداخت و انتقال وضعیت از ۱ به ۲
     */
    public function pay($id, Request $request)
    {
        $userId = $request->user()->id;
        $now = Carbon::now();

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$pharmacyRequest) {
            return response()->json(['success' => false, 'message' => 'درخواست یافت نشد'], 404);
        }

        if ($pharmacyRequest->status != 1) {
            return response()->json(['success' => false, 'message' => 'فقط درخواست‌های با وضعیت "در انتظار پرداخت" قابل پرداخت هستند'], 400);
        }

        if (!$pharmacyRequest->total_price || $pharmacyRequest->total_price <= 0) {
            return response()->json(['success' => false, 'message' => 'مبلغ فاکتور نامعتبر است'], 422);
        }

        DB::beginTransaction();

        try {
            $totalPrice = $pharmacyRequest->total_price;
            $pharmacyId = $pharmacyRequest->pharmacy_id;

            $orderId = $this->financialService->createOrder(
                userId:      $userId,
                reasonId:    6,
                reasonRef:   $id,
                amount:      $totalPrice,
                description: "پرداخت فاکتور درخواست داروخانه #{$id}"
            );

            $paymentId = $this->financialService->createPayment(
                userId:    $userId,
                orderId:   $orderId,
                reasonId:  6,
                reasonRef: $id,
                amount:    $totalPrice,
                gateway:   'mock'
            );

            $authority = 'MOCK-' . str_pad($paymentId, 30, '0', STR_PAD_LEFT);
            $refId     = 'SIM-' . strtoupper(Str::random(12));

            DB::table('payments')
                ->where('id', $paymentId)
                ->update([
                    'authority'  => $authority,
                    'updated_at' => $now,
                ]);

            $patient = DB::table('users')
                ->where('id', $userId)
                ->first(['name']);
            $patientName = trim(($patient->name ?? 'کاربر ناشناس'));

            $description = "درآمد از فروش دارو - درخواست #{$id} (سفارش #{$orderId}) - بیمار: {$patientName}";

            $this->financialService->completePayment(
                paymentId:                      $paymentId,
                authority:                      $authority,
                refId:                          $refId,
                providerId:                     $pharmacyId,
                providerId_payment_description: $description
            );

            DB::table('users_pharmacy_requests')
                ->where('id', $id)
                ->update([
                    'status'     => 2,
                    'updated_at' => $now
                ]);

            DB::commit();

            Log::info('Pharmacy request paid successfully via mock', [
                'request_id'  => $id,
                'user_id'     => $userId,
                'pharmacy_id' => $pharmacyId,
                'order_id'    => $orderId,
                'payment_id'  => $paymentId,
                'amount'      => $totalPrice,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'پرداخت با موفقیت انجام شد. سفارش به مرحله آماده‌سازی رفت.',
                'data'    => [
                    'request_id'  => $id,
                    'total_price' => $totalPrice,
                    'payment'     => [
                        'order_id'   => $orderId,
                        'payment_id' => $paymentId,
                        'ref_id'     => $refId,
                        'amount'     => $totalPrice,
                        'status'     => 'completed',
                    ],
                ],
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Pharmacy request payment failed', [
                'request_id' => $id,
                'user_id'    => $userId,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در فرآیند پرداخت: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function statusLabel($status)
    {
        return match ((int)$status) {
            1 => 'در انتظار پرداخت',
            2 => 'در حال آماده‌سازی',
            3 => 'آماده ارسال',
            4 => 'در حال ارسال',
            5 => 'تحویل شده',
            6 => 'تکمیل شده',
            7 => 'لغو شده',
            default => 'نامشخص',
        };
    }

    /**
     * ثبت درخواست جدید داروخانه توسط کاربر
     */
    public function storeRequest(Request $request)
    {
        $userId = auth()->id();

        // اعتبارسنجی
        $validator = Validator::make($request->all(), [
            'delivery_type'      => 'required|in:0,1', // 0: حضوری, 1: ارسال
            'user_address_id'    => 'required_if:delivery_type,1|nullable|integer',
            'prescription_code'  => 'nullable|string',
            'medicines_text'     => 'nullable|string',
            'prescription_image' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'has_insurance'      => 'nullable|boolean',
            'description'        => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $pendingExists = DB::table('users_pharmacy_requests')
            ->where('user_id', $userId)
            ->whereIn('status', [0, 1])
            ->exists();

        if ($pendingExists) {
            return response()->json([
                'success' => false,
                'message' => 'شما یک درخواست در انتظار پرداخت یا بررسی دارید. لطفاً ابتدا آن را تعیین تکلیف نمایید.',
            ], 400);
        }

        // ۱. تعیین نوع نسخه (prescription_type_id)
        $prescriptionTypeId = 1;
        if ($request->hasFile('prescription_image')) {
            $prescriptionTypeId = 3;
        } elseif ($request->filled('prescription_code')) {
            $prescriptionTypeId = 2;
        }

        $uploadedFiles = [];
        if ($request->hasFile('prescription_image')) {
            $path = $request->file('prescription_image')->store('prescriptions', 'local');
            $uploadedFiles[] = $path;
        }

        $deliveryType  = (int) $request->input('delivery_type');
        $userAddressId = $deliveryType === 1 ? $request->input('user_address_id') : null;

        // ۲. ساختاردهی فیلد details جهت نگهداری نسخه
        $details = [
            'code'            => $request->input('prescription_code'),
            'medicines'       => $request->input('medicines_text'),
            'files'           => $uploadedFiles,
            'delivery_type'   => $deliveryType,
            'user_address_id' => $userAddressId,
            'has_insurance'   => $request->input('has_insurance', 0),
            'description'     => $request->input('description')
        ];

        DB::beginTransaction();
        try {
            // ۳. ثبت اطلاعات در جدول users_prescriptions
            $prescriptionId = DB::table('users_prescriptions')->insertGetId([
                'user_id'              => $userId,
                'prescription_type_id' => $prescriptionTypeId,
                'status'               => 1,
                'details'              => json_encode($details, JSON_UNESCAPED_UNICODE),
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            // ۴. ثبت درخواست در جدول users_pharmacy_requests به همراه delivery_type و user_address_id مستقیم
            $requestId = DB::table('users_pharmacy_requests')->insertGetId([
                'user_id'                  => $userId,
                'pharmacy_id'              => null,
                'pharmacy_request_type_id' => null,
                'prescription_id'          => $prescriptionId,
                'delivery_type'            => $deliveryType,      // 0: حضوری | 1: ارسال
                'user_address_id'          => $userAddressId,     // شناسه آدرس در صورت انتخاب پیک
                'total_price'              => null,
                'status'                   => 0,
                'created_at'               => now(),
                'updated_at'               => now(),
            ]);

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'درخواست شما با موفقیت برای داروخانه‌های اطراف ارسال شد.',
                'data'    => [
                    'request_id'      => $requestId,
                    'prescription_id' => $prescriptionId,
                    'delivery_type'   => $deliveryType,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'خطا در ثبت درخواست.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
