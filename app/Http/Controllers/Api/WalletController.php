<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Payment\OrderService;
use App\Services\Payment\PaymentService;
use Exception;

class WalletController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly PaymentService $paymentService
    ) {}

    /**
     * درخواست شارژ کیف پول توسط کاربر
     */
    public function chargeWallet(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|integer|min:10000', // حداقل ۱۰ هزار تومان (یا ریال بسته به واحد شما)
            'gateway' => 'nullable|string|in:saman,zarinpal',
        ]);

        $userId = $request->user()->id;
//        $amount = $validated['amount'];
        $amount = 15000;

        DB::beginTransaction();
        try {
            // ۱. پیدا کردن یا ساختن کیف پول برای داشتن شناسه آن (Wallet ID)
            $wallet = DB::table('wallets')->where('user_id', $userId)->first();

            if ($wallet) {
                $walletId = $wallet->id;
            } else {
                $walletId = DB::table('wallets')->insertGetId([
                    'user_id'    => $userId,
                    'balance'    => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // ۲. ایجاد سفارش مالی با reason_id = 2 (شارژ کیف پول) و reason_ref = $walletId
            $orderResult = $this->orderService->createOrReuse(
                userId: $userId,
                reasonId: 2,
                reasonRef: $walletId,
                amount: $amount,
                description: "شارژ آنلاین کیف پول کاربر #{$userId}"
            );

            // ۳. دریافت لینک از درگاه پرداخت
            // فرانت کاربر را باید به آدرس تولید شده در مرحله بعد بفرستد
            $callbackUrl =  'https://mediraai.com/api/pg/call_back';

            $paymentResult = $this->paymentService->initiate(
                orderId:     $orderResult['order_id'],
                userId:      $userId,
                callbackUrl: $callbackUrl
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'لینک پرداخت با موفقیت ایجاد شد.',
                'data' => [
                    'order_id'    => $orderResult['order_id'],
                    'payment_id'  => $paymentResult['payment_id'],
                    'payment_url' => $paymentResult['payment_url'],
                ]
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'خطا در ایجاد درخواست شارژ: ' . $e->getMessage()
            ], 500);
        }
    }
}
