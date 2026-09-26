<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\OrderService;
use App\Services\Payment\PaymentService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class MedicalRequestController extends Controller
{
    /**
     * دریافت جزئیات درخواست پرستاری/پزشکی برای کاربر
     */
    public function getRequestDetail(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;

            $medicalRequest = DB::table('user_medical_center_requests as ur')
                ->leftJoin('medical_centers_info as mc', 'ur.medical_center_id', '=', 'mc.user_id')
                ->leftJoin('medical_center_staffs as staff', 'ur.staff_id', '=', 'staff.id') // پرستار تخصیص یافته
                ->where('ur.id', $id)
                ->where('ur.user_id', $userId)
                ->select(
                    'ur.id',
                    'ur.status',
                    'ur.total_price',
                    'ur.created_at',
                    'ur.start_time',
                    'ur.extra_info',
                    'mc.name as center_name',
                    'staff.name as staff_name',
                    'staff.mobile as staff_mobile'
                )
                ->first();

            if (!$medicalRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'درخواست یافت نشد یا متعلق به شما نیست.'
                ], 404);
            }

            $services = DB::table('user_medical_center_request_services as urs')
                ->join('medical_center_services as mcs', 'urs.medical_center_service_id', '=', 'mcs.id')
                ->join('medical_services as ms', 'mcs.medical_service_id', '=', 'ms.id')
                ->where('urs.user_medical_center_request_id', $id)
                ->select('ms.name as service_name', 'urs.price')
                ->get();

            $data = [
                'id' => $medicalRequest->id,
                'status' => $medicalRequest->status,
                'total_price' => (float) $medicalRequest->total_price,
                'created_at' => $medicalRequest->created_at,
                'start_time' => $medicalRequest->start_time,
                'center_name' => $medicalRequest->center_name,
                'staff' => $medicalRequest->staff_name ? [
                    'name' => $medicalRequest->staff_name,
                    'mobile' => $medicalRequest->staff_mobile
                ] : null,
                'services' => $services,
                'extra_info' => json_decode($medicalRequest->extra_info, true) ?? [],
            ];

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * دریافت لیست خدمات فعال
     */
    public function getServices()
    {
        $services = DB::table('medical_services')
            ->where('status',1)
            ->select('id', 'name', 'slug')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $services,
        ]);
    }

    /**
     * دریافت مراکز درمانی ارائه‌دهنده خدمات انتخابی
     */
    public function getCenters(Request $request)
    {
        try {
            $request->validate([
                'service_ids'=> 'required|array',
                'service_ids.*' => 'integer|exists:medical_services,id',
            ]);

            $serviceIds = $request->input('service_ids');

            $centers = DB::table('medical_centers_info as mc')
                ->join('users as u', 'mc.user_id', '=', 'u.id')
                ->join('medical_center_services as mcs', 'mc.user_id', '=', 'mcs.medical_center_id')
                ->whereIn('mcs.medical_service_id', $serviceIds)
                ->where('mcs.status', 1)
                ->where('u.status', 1)
                ->where('mc.status', 1)
                ->select(
                    'mc.user_id as id',
                    'mc.name',
                    'mc.address',
                    'mc.lat',
                    'mc.lng',
                    DB::raw('SUM(mcs.price) as total_estimated_price')
                )
                ->groupBy('mc.user_id', 'mc.name', 'mc.address', 'mc.lat', 'mc.lng')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $centers,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'خطای اعتبارسنجی', 'errors'  => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'خطا در دریافت مراکز درمانی: ' . $e->getMessage()], 500);
        }
    }


    /**
     * لغو درخواست خدمات درمانی/پرستاری توسط کاربر
     */
    public function cancelRequest($id, Request $request)
    {
        $userId = $request->user()->id;

        // ۱. بررسی وجود و وضعیت درخواست
        $medicalRequest = DB::table('user_medical_center_requests')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$medicalRequest) {
            return response()->json([
                'success' => false,
                'message' => 'درخواست یافت نشد یا متعلق به شما نیست.'
            ], 404);
        }

        // فقط در وضعیت 0 (در انتظار پرداخت) و 1 (در انتظار انتخاب پرستار) امکان لغو هست
        if (!in_array((int)$medicalRequest->status, [0, 1])) {
            return response()->json([
                'success' => false,
                'message' => 'این درخواست در مرحله‌ای است که دیگر امکان لغو آن توسط شما وجود ندارد.'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // ۲. تغییر وضعیت به ۵ (لغو شده)
            DB::table('user_medical_center_requests')
                ->where('id', $id)
                ->update([
                    'status' => 5, // 5 = لغو شده
                    'updated_at' => now(),
                ]);

            // ۳. در صورت وجود سفارش مالی (Order) برای آن، آن را هم کنسل می‌کنیم
            // reason_id = 4 مخصوص خدمات درمانی/پرستاری است
            if ((int)$medicalRequest->status === 0) {
                DB::table('orders')
                    ->where('user_id', $userId)
                    ->where('reason_id', 4)
                    ->where('reason_ref', $id)
                    ->where('status', 1) // 1 = در انتظار پرداخت در جدول orders
                    ->update([
                        'status' => 3, // 3 = لغو شده در جدول orders
                        'cancelled_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'درخواست خدمات پرستاری با موفقیت لغو شد.'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Medical request cancellation failed', [
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
     * ثبت درخواست خدمات پزشکی و اتصال به درگاه پرداخت
     */
    public function storeRequest(Request $request, OrderService $orderService, PaymentService $paymentService)
    {
        try {
            $validated = $request->validate([
                'medical_center_id' => 'required|integer|exists:medical_centers_info,user_id',
                'service_ids'       => 'required|array',
                'service_ids.*'     => 'integer|exists:medical_services,id',
                'gender_pref'       => 'nullable|string',
                'condition'         => 'nullable|string',
                'is_urgent'         => 'boolean',
                'address'           => 'required|string',
                'time_type_id'      => 'required|integer',
            ]);

            $userId= $request->user()->id;
            $centerId  = $validated['medical_center_id'];
            $now       = Carbon::now();

            $services = DB::table('medical_center_services')
                ->where('medical_center_id', $centerId)
                ->whereIn('medical_service_id', $validated['service_ids'])
                ->where('status', 1)
                ->get();

            if ($services->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'خدمات انتخاب‌شده برای این مرکز درمانی یافت نشد.'], 422);
            }

            $totalPrice = $services->sum('price');

            DB::beginTransaction();

            try {
                // ۱. ثبت درخواست اصلی (وضعیت 0: در انتظار پرداخت)
                $requestId = DB::table('user_medical_center_requests')->insertGetId([
                    'user_id'           => $userId,
                    'medical_center_id' => $centerId,
                    'address_id'        => null,
                    'time_type_id'      => $validated['time_type_id'],
                    'start_time'        => $now->toDateTimeString(),
                    'total_price'       => $totalPrice,
                    'status'            => 0, // در انتظار پرداخت
                    'extra_info'        => json_encode([
                        'gender_pref'=> $validated['gender_pref'] ?? null,
                        'condition'      => $validated['condition'] ?? null,
                        'is_urgent'      => $validated['is_urgent'] ?? false,
                        'custom_address' => $validated['address'],
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);

                // ۲. ثبت آیتم‌های سرویس
                $serviceRows = [];
                foreach ($services as $service) {
                    $serviceRows[] = [
                        'user_medical_center_request_id' => $requestId,
                        'medical_center_service_id'      => $service->id,
                        'price'                          => $service->price,
                        'date'                           => $now->toDateString(),
                        'created_at'                     => $now,
                        'updated_at'                     => $now,
                    ];
                }
                DB::table('user_medical_center_request_services')->insert($serviceRows);

                // ۳. ایجاد سفارش مالی با reason_id = 4
                $orderResult = $orderService->createOrReuse(
                    userId: $userId,
                    reasonId: 4,
                    reasonRef: $requestId,
                    amount: 15000,
                    description: "پرداخت خدمات پرستاری/درمانی - درخواست #{$requestId}"
                );

                // ۴. ساخت لینک پرداخت با درگاه سامان
                $callbackUrl = config('payment.saman.callback_url', 'https://api.mediraai.com/api/pg/call_back');

                $paymentResult = $paymentService->initiate(
                    orderId: $orderResult['order_id'],
                    userId: $userId,
                    callbackUrl: $callbackUrl
                );

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'در حال انتقال به درگاه پرداخت...',
                    'data'    => [
                        'request_id'  => $requestId,
                        'order_id'    => $orderResult['order_id'],
                        'payment_id'  => $paymentResult['payment_id'],
                        'payment_url' => $paymentResult['payment_url'],
                    ],
                ], 201);

            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'خطای اعتبارسنجی', 'errors'  => $e->errors()], 422);

        } catch (Exception $e) {
            Log::error('Medical request store failed', [
                'user_id' => $request->user()?->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json(['success' => false, 'message' => 'خطا در ثبت درخواست: ' . $e->getMessage()], 500);
        }
    }
}
