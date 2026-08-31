<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinancialService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class MedicalRequestController extends Controller
{
    private FinancialService $financialService;

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

            // دریافت لیست خدمات داخل این درخواست
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

    public function __construct(FinancialService $financialService)
    {
        $this->financialService = $financialService;
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
            return response()->json([
                'success' => false,
                'message' => 'خطای اعتبارسنجی',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت مراکز درمانی: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ثبت درخواست خدمات پزشکی + پرداخت شبیه‌سازی‌شده از کیف پول
     */
    public function storeRequest(Request $request)
    {
        try {
            $validated = $request->validate([
                'medical_center_id' => 'required|integer|exists:medical_centers_info,user_id',
                'service_ids'       => 'required|array',
                'service_ids.*'     => 'integer|exists:medical_services,id',
                'gender_pref'       => 'nullable|string',
                'condition'=> 'nullable|string',
                'is_urgent'         => 'boolean',
                'address'           => 'required|string',
                'time_type_id'      => 'required|integer',
            ]);

            $userId= $request->user()->id;
            $centerId  = $validated['medical_center_id'];
            $now       = Carbon::now();

            // ──۱. واکشی سرویس‌های انتخاب‌شده برای این مرکز ─────────────
            $services = DB::table('medical_center_services')
                ->where('medical_center_id', $centerId)
                ->whereIn('medical_service_id', $validated['service_ids'])
                ->where('status', 1)
                ->get();

            if ($services->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'خدمات انتخاب‌شده برای این مرکز درمانی یافت نشد.',], 422);
            }

            $totalPrice = $services->sum('price');

            // ── ۲.ثبت درخواست + فرآیند مالی (داخل تراکنش) ─────────────
            DB::beginTransaction();

            try {
                //۲.۱ ثبت درخواست اصلی
                $requestId = DB::table('user_medical_center_requests')->insertGetId([
                    'user_id'           => $userId,
                    'medical_center_id' => $centerId,
                    'address_id'        => null,
                    'time_type_id'      => $validated['time_type_id'],
                    'start_time'        => $now->toDateTimeString(),
                    'total_price'       => $totalPrice,
                    'status'            => 1, // در انتظار تایید
                    'extra_info'        => json_encode([
                        'gender_pref'=> $validated['gender_pref'] ?? null,
                        'condition'      => $validated['condition'] ?? null,
                        'is_urgent'      => $validated['is_urgent'] ?? false,
                        'custom_address' => $validated['address'],
                    ], JSON_UNESCAPED_UNICODE),'created_at'        => $now,
                    'updated_at'        => $now,
                ]);

                // ۲.۲ ثبت آیتم‌های سرویس
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

                // ── ۳. فرآیند مالی (همانند ReservationController) ─────────

                // ۳.۱ ایجاد سفارش
                $orderId = $this->financialService->createOrder(
                    userId:$userId,
                    reasonId:    4, // medical_service
                    reasonRef:   $requestId,
                    amount:      $totalPrice,
                    description: "درخواست خدمات پزشکی #{$requestId}"
                );

                // ۳.۲ ایجاد پرداخت (gateway: mock)
                $paymentId = $this->financialService->createPayment(
                    userId:    $userId,
                    orderId:   $orderId,
                    reasonId:  4,
                    reasonRef: $requestId,
                    amount:    $totalPrice,
                    gateway:   'mock'
                );

                // ۳.۳ شبیه‌سازی authority (مشابه ReservationController)
                $authority = 'MOCK-' . str_pad($paymentId, 30, '0', STR_PAD_LEFT);
                $refId     = 'SIM-' . strtoupper(Str::random(12));

                // ثبت authority در پرداخت (مشابه reserveSlot)
                DB::table('payments')
                    ->where('id', $paymentId)
                    ->update([
                        'authority'  => $authority,
                        'updated_at' => $now,
                    ]);

                // ۳.۴ تکمیل پرداخت + کسر از کیف پول کاربر + واریز به مرکز درمانی
                // completePayment داخلاً:
                //   - واریز از درگاه mock به کیف پول کاربر
                //   - برداشت بابت سفارش از کیف پول کاربر
                //   - واریز به کیف پول مرکز درمانی (providerId)
                $patient = DB::table('users')
                    ->where('id', $userId)
                    ->first(['name']);
                $patientName = trim(($patient->name ?? ''));
                $description = "درآمد از ارائه خدمات درمانی - درخواست #{$requestId} (سفارش #{$orderId}) - بیمار: {$patientName}";

                $this->financialService->completePayment(
                    paymentId:  $paymentId,
                    authority:  $authority,
                    refId:      $refId,
                    providerId: $centerId,
                    providerId_payment_description:$description
                );

                DB::commit();

                Log::info('Medical request created and paid', [
                    'request_id' => $requestId,
                    'user_id'    => $userId,
                    'center_id'  => $centerId,
                    'order_id'   => $orderId,
                    'payment_id' => $paymentId,
                    'amount'     => $totalPrice,]);

                return response()->json([
                    'success' => true,
                    'message' => 'درخواست شما با موفقیت ثبت و پرداخت انجام شد.',
                    'data'    => [
                        'request_id'  => $requestId,
                        'total_price' => $totalPrice,
                        'payment'     => [
                            'order_id'   => $orderId,
                            'payment_id' => $paymentId,
                            'ref_id'     => $refId,
                            'amount'     => $totalPrice,
                            'status'     => 'completed',
                        ],
                    ],
                ], 201);

            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطای اعتبارسنجی',
                'errors'  => $e->errors(),
            ], 422);

        } catch (Exception $e) {
            Log::error('Medical request store failed', [
                'user_id' => $request->user()?->id,
                'error'   => $e->getMessage(),'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در ثبت درخواست: ' . $e->getMessage(),
            ], 500);
        }
    }
}
