<?php

namespace App\Http\Controllers\Api\Owner\Beauty;

use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BeautyProfileController extends \App\Http\Controllers\Controller
{

    use ApiResponse;

    public function finance(Request $request)
    {
        $userId = auth()->id();
        $period = $request->input('period', 'month');
        $wallet=DB::table('wallets')->where('user_id',$userId)->first();
        $query = DB::table('wallet_transactions')
            ->leftJoin('orders', function ($join) {
                $join->on('wallet_transactions.subject_id', '=', 'orders.id')->where('wallet_transactions.type', '=', 1)
                    ->where('wallet_transactions.subject_type', '=', 2)
                    ->where('orders.reason_id', '=', 10);
            })
            ->select(
                'wallet_transactions.*',
                'orders.reason_ref'
            )
            ->where('wallet_transactions.wallet_id', $wallet->id);

        // فیلتر زمانی
        $now = Carbon::now();
        if ($period === 'day') {
            $query->whereDate('wallet_transactions.created_at', $now->toDateString());
        } elseif ($period === 'week') {
            $query->where('wallet_transactions.created_at', '>=', $now->subDays(7));
        } elseif ($period === 'month') {
            $query->where('wallet_transactions.created_at', '>=', $now->subDays(30));
        }

        $transactions = $query->orderBy('wallet_transactions.created_at', 'desc')->get();

        // محاسبه مجموع واریزی‌ها (type = 1)
        $totalIncome = $transactions->where('wallet_transactions.type', 1)->sum('amount');

        // فرمت کردن داده‌ها برای فرانت‌اند
        $rows = $transactions->map(function ($trx) {
            return [
                'id'          => $trx->id,
                'code'        => 'TRX-' . $trx->id,
                'amount'      => (float) $trx->amount,
                'type'        => (int) $trx->type,
                'description' => $trx->description ?? 'تراکنش سیستمی',
                'date'        => \Carbon\Carbon::parse($trx->created_at)->format('Y-m-d H:i:s'),
                'reason_ref'  => $trx->reason_ref ?? null,
            ];
        })->values();


        return response()->json([
            'status' => 200,
            'data' => [
                'balance' => $wallet->balance,
                'totalIncome' => $totalIncome,
                'rows' => $rows
            ]
        ]);
    }
    public function show(Request $request)
    {
        $beauty_clinicId = $request->beauty_clinic_id;

        $lab = DB::table('beauty_clinic_info')
            ->where('user_id', $beauty_clinicId)
            ->first();

        if (!$lab) {
            return $this->success(null);
        }

        $data = (array) $lab;
        $data['image_url'] = !empty($lab->image)
            ? (str_starts_with($lab->image, 'http')
                ? $lab->image
                : 'https://api.mediraai.com/storage/' . ltrim($lab->image, '/'))
            : null;

        return $this->success($data);
    }
}
