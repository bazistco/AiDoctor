<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\OrderService;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $callbackUrl = route('pg.callback');

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
    public function callback(Request $request): JsonResponse
    {
        // فقط POST از IP های سامان مجاز است
        // در production می‌توان IP whitelist اضافه کرد
        $payload = $request->all();

        dd($payload);
        // اعتبارسنجی اولیه پارامترهای ضروری
        if (empty($payload['ResNum'])) {
            Log::warning('[PaymentController][CB] Missing ResNum', [
                'ip'      => $request->ip(),
                'payload' => $payload,
            ]);
            return response()->json(['success' => false, 'error' => 'invalid_request'], 400);
        }

        // بررسی امضا / MID — اطمینان از اینکه درخواست از سامان آمده
        $this->verifyGatewayIdentity($payload, $request);

        $result = $this->paymentService->handleCallback($payload);

        // سامان response code را چک می‌کند — همیشه 200 برگردان
        return response()->json([
            'success'    => $result['success'],
            'payment_id' => $result['payment_id'] ?? null,
            'ref_num'    => $result['ref_num']     ?? null,
            'error'      => $result['error']       ?? null,
        ]);
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
            abort(403, 'Invalid gateway identity');
        }
    }
}
