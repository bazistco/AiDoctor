<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\FinancialService;
use Illuminate\Support\Str;
use Exception;

class WalletController extends Controller
{
    protected FinancialService $financialService;

    public function __construct(FinancialService $financialService)
    {
        $this->financialService = $financialService;
    }

    /**
     * شبیه‌سازی کامل فرآیند شارژ کیف پول (بدون نیاز به درگاه واقعی)
     */
    public function mockCharge(Request $request)
    {
        try {
            $validated = $request->validate([
                'amount' => 'required|integer|min:10000',
            ]);

            $userId = $request->user()->id;
            $amount = $validated['amount'];

            DB::beginTransaction();

            try {
                // ۱. پیدا کردن یا ساختن کیف پول
                $wallet = DB::table('wallets')->where('user_id', $userId)->first();
                $walletId = $wallet->id ?? DB::table('wallets')->insertGetId([
                    'user_id'    => $userId,
                    'balance'    => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // ۲. ایجاد سفارش (شارژ کیف پول - reason_id = 2)
                $orderId = $this->financialService->createOrder(
                    userId: $userId,
                    reasonId: 2,
                    reasonRef: $walletId,
                    amount: $amount,
                    description: "شارژ مستقیم کیف پول (شبیه‌سازی)"
                );

                // ۳. ایجاد رکورد پرداخت
                $paymentId = $this->financialService->createPayment(
                    userId: $userId,
                    orderId: $orderId,
                    reasonId: 2,
                    reasonRef: $walletId,
                    amount: $amount,
                    gateway: 'mock'
                );

                // اختصاص یک Authority فیک
                $authority = 'MOCK-AUTH-' . Str::random(10);
                DB::table('payments')
                    ->where('id', $paymentId)
                    ->update(['authority' => $authority, 'updated_at' => now()]);

                // ۴. تکمیل بلافاصله پرداخت به صورت شبیه‌سازی شده
                $refId = 'MOCK-REF-' . Str::random(10);

                $this->financialService->completePayment(
                    paymentId: $paymentId,
                    authority: $authority,
                    refId: $refId,
                    providerId: null,
                    providerId_payment_description: null
                );

                DB::commit();

                // واکشی موجودی جدید
                $newBalance = $this->financialService->getWalletBalance($userId);

                return response()->json([
                    'status'  => 200,
                    'message' => 'کیف پول با موفقیت شارژ شد.',
                    'data'    => [
                        'new_balance' => $newBalance
                    ]
                ], 200);

            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            Log::error('Mock charge error: ' . $e->getMessage());
            return response()->json([
                'status'  => 500,
                'message' => 'خطا در شارژ کیف پول'
            ], 500);
        }
    }
}
