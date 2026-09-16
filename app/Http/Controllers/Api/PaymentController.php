<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\OrderService;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly OrderService   $orderService,
        private readonly PaymentService $paymentService,
    ) {}

    // ═══════════════════════════════════════════════════════════════
    // Endpoint 1: POST /api/payments/order
    // ═══════════════════════════════════════════════════════════════

    /**
     * ایجاد سفارش — Idempotent
     *
     * Body: { reason_id, reason_ref, amount, description? }
     * Auth: Sanctum (required)
     */
    public function initiateAppointmentPayment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'min:1'],
            'gateway'  => ['nullable', 'string', 'in:saman,zarinpal'],
        ]);

        $userId = $request->user()->id;
        $orderId = (int) $data['order_id'];

        // ۱. بررسی وجود سفارش
        $order = DB::table('orders')
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'سفارش یافت نشد.'], 404);
        }

        if ((int)$order->status !== \App\Services\Payment\OrderService::STATUS_PENDING) {
            return response()->json(['success' => false, 'message' => 'این سفارش قابل پرداخت نیست (ممکن است پرداخت شده یا لغو شده باشد).'], 422);
        }

        // ۲. دریافت اطلاعات نوبت
        $slotId = $order->reason_ref;
        $slot = DB::table('appointment_slots')->where('id', $slotId)->first();

        if (!$slot) {
            return response()->json(['success' => false, 'message' => 'نوبت یافت نشد.'], 404);
        }

        // ۳. بررسی اعتبار رزرو موقت (قفل Redis)
        $reservationKey = "slot:reservation:{$slotId}";
        $lockExists = Redis::exists($reservationKey);
        $isValidForPayment = false;

        if ($lockExists) {
            $lockData = json_decode(Redis::get($reservationKey), true);
            // بررسی اینکه آیا رزرو موقت متعلق به همین کاربر و همین سفارش است؟
            if (($lockData['user_id'] ?? null) == $userId && ($lockData['order_id'] ?? null) == $orderId) {
                $isValidForPayment = true;
            }
        }

        // ۴. اگر مهلت رزرو موقت تمام شده یا نوبت متعلق به شخص دیگری است
        if (!$isValidForPayment) {
            DB::transaction(function () use ($orderId, $slotId, $userId, $slot) {
                // الف) لغو سفارش
                DB::table('orders')->where('id', $orderId)->update([
                    'status' => \App\Services\Payment\OrderService::STATUS_CANCELLED,
                    'updated_at' => now()
                ]);

                // ب) لغو و باطل کردن پرداخت‌های معلق (درگاه‌های ایجاد شده)
                DB::table('payments')
                    ->where('order_id', $orderId)
                    ->where('status', \App\Services\Payment\PaymentService::PAY_PENDING)
                    ->update([
                        'status' => \App\Services\Payment\PaymentService::PAY_FAILED,
                        'last_error' => 'مهلت پرداخت به پایان رسید و نوبت لغو شد.',
                        'updated_at' => now()
                    ]);

                // ج) پاک کردن رزرو نوبت (آزاد کردن اسلات)
                if ($slot->status !== 'booked') {
                    DB::table('appointment_slots')->where('id', $slotId)->update([
                        'status' => 'available',
                        'patient_id' => null,
                        'extra_detail' => null,
                        'updated_at' => now(),
                    ]);
                }
            });

            // پاک کردن کلید ردیس (محض اطمینان)
            Redis::del($reservationKey);

            return response()->json([
                'success' => false,
                'message' => 'مهلت ۱۵ دقیقه‌ای پرداخت شما به پایان رسیده و نوبت لغو شد. لطفاً مجدداً نوبت بگیرید.'
            ], 410); // 410 Gone
        }

        // ۵. در صورت معتبر بودن نوبت، فراخوانی سرویس پرداخت (ساخت یا بازگردانی توکن قبلی)
        // دقت کنید که PaymentService شما خودش چک می‌کند که اگر درگاه باز و توکن معتبر است، همون قبلی رو بده (Idempotent)
        $callbackUrl =  'http://mediraai.com/api/pg/call_back';

        try {
            $result = $this->paymentService->initiate(
                orderId:     $orderId,
                userId:      $userId,
                callbackUrl: $callbackUrl
            );

            return response()->json([
                'success'     => true,
                'payment_id'  => $result['payment_id'],
                'payment_url' => $result['payment_url'],
                'res_num'     => $result['res_num'],
            ]);

        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در ارتباط با درگاه پرداخت: ' . $e->getMessage(),
            ], 422);
        }
    }
    public function createOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason_id'   => ['required', 'integer', 'min:1'],
            'reason_ref'  => ['required', 'integer', 'min:1'],
            'amount'      => ['required', 'integer', 'min:1000'],  // حداقل ۱۰۰۰ ریال
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->orderService->createOrReuse(
            userId:      $request->user()->id,
            reasonId:    (int) $data['reason_id'],
            reasonRef:   (int) $data['reason_ref'],
            amount:      (int) $data['amount'],
            description: $data['description'] ?? null,
        );

        return response()->json([
            'success'  => true,
            'order_id' => $result['order_id'],
            'amount'   => $result['amount'],
            'is_new'   => $result['is_new'],
        ], $result['is_new'] ? 201 : 200);
    }

    // ═══════════════════════════════════════════════════════════════
    // Endpoint 2: POST /api/payments/initiate
    // ═══════════════════════════════════════════════════════════════

    /**
     * ایجاد درگاه — دریافت توکن از سامان و برگرداندن URL پرداخت
     *
     * Body: { order_id, cell_number? }
     * Auth: Sanctum (required)
     */
    public function initiate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id'    => ['required', 'integer', 'min:1'],
            'cell_number' => ['nullable', 'string', 'regex:/^09[0-9]{9}$/'],
        ]);

        // URL کالبک باید HTTPS باشد در production
        $callbackUrl = 'http://mediraai.com/api/pg/call_back';

        try {
            $result = $this->paymentService->initiate(
                orderId:     (int) $data['order_id'],
                userId:      $request->user()->id,
                callbackUrl: $callbackUrl,
                cellNumber:  $data['cell_number'] ?? null,
            );

            return response()->json([
                'success'     => true,
                'payment_id'  => $result['payment_id'],
                'payment_url' => $result['payment_url'],
                'res_num'     => $result['res_num'],
            ]);

        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Endpoint 3: POST /api/payments/callback   ← بدون Auth Middleware
    // ═══════════════════════════════════════════════════════════════

    /**
     * بازگشت از درگاه
     *
     * POST از سامان — هیچ session/auth ندارد
     * پارامترها: State, Status, ResNum, RefNum, RRN, Amount, Wage,
     *            AffectiveAmount, SecurePan, MID, Token
     *
     * باید در routes/api.php خارج از middleware('auth:sanctum') باشد
     */
    /**
     * ۳. بازگشت از درگاه (Callback) — فراخوانی توسط مرورگر کاربر از سمت شاپرک
     */
    public function callback(Request $request): RedirectResponse
    {
        $payload = $request->all();
        Log::info('[PaymentController][CB] SEP Callback Received:', [
            'payload'           => $payload,
            'ip'                => $request->ip(),                          // X-Real-IP (از طریق TrustProxies)
            'forwarded_for'     => $request->header('X-Forwarded-For'),
            'forwarded_proto'   => $request->header('X-Forwarded-Proto'),
            'real_ip_header'    => $request->header('X-Real-IP'),
            'host'              => $request->header('Host'),
        ]);
        // آدرس صفحه نتیجه در فرانت‌‌اند React
        $frontendResultUrl = config('payment.frontend_result_url', 'https://app.mediraai.com/payment/result');

        // اعتبارسنجی پارامترهای ضروری
        if (empty($payload['ResNum']) || empty($payload['State'])) {
            Log::warning('[PaymentController][CB] Missing essential parameters', [
                'ip'      => $request->ip(),
                'payload' => $payload,
            ]);

            return redirect()->away($frontendResultUrl . '?status=failed&message=' . urlencode('اطلاعات بازگشتی از درگاه نامعتبر است.'));
        }

        // بررسی هویت درگاه (اختیاری بر اساس MID)
        $this->verifyGatewayIdentity($payload, $request);

        try {
            // عملیات وریفای و ثبت وضعیت در دیتابیس
            $result = $this->paymentService->handleCallback($payload);

            if (!empty($result['success']) && $result['success'] === true) {
                $queryParams = http_build_query([
                    'status'     => 'success',
                    'ref_num'    => $result['ref_num'] ?? ($payload['RefNum'] ?? ''),
                    'res_num'    => $payload['ResNum'],
                    'payment_id' => $result['payment_id'] ?? '',
                ]);

                return redirect()->away($frontendResultUrl . '?' . $queryParams);
            }

            // در صورت عدم موفقیت تراکنش در درگاه یا وریفای
            $errorMsg = $result['error'] ?? 'پرداخت توسط کاربر لغو شد یا با خطا مواجه گردید.';
            return redirect()->away($frontendResultUrl . '?status=failed&message=' . urlencode($errorMsg) . '&res_num=' . $payload['ResNum']);

        } catch (\Throwable $e) {
            Log::error('[PaymentController][CB] Exception during verify: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->away($frontendResultUrl . '?status=error&message=' . urlencode('خطا در پردازش و تایید تراکنش.'));
        }
    }


    // ─── متد کمکی: بررسی هویت درگاه ──────────────────────────────

    /**
     * بررسی می‌کند MID با terminal_id ما مطابقت داشته باشد
     * در مستند سامان نسخه ۳.۶ پارامتر MID در کالبک ارسال می‌شود
     */
    private function verifyGatewayIdentity(array $payload, Request $request): void
    {
        $mid = (string) ($payload['MID'] ?? '');

        if ($mid === '') {
            // اگر MID نیامد، فقط لاگ کن (برخی نسخه‌های قدیمی ارسال نمی‌کنند)
            Log::info('[PaymentController][CB] MID absent', ['ip' => $request->ip()]);
            return;
        }

        if ($mid !== config('payment.saman.terminal_id')) {
            Log::error('[PaymentController][CB] MID mismatch', [
                'received' => $mid,
                'ip'       => $request->ip(),
            ]);
            return;
        }
    }
}
