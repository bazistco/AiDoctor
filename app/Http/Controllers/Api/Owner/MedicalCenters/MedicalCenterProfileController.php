<?php
// app/Http/Controllers/Owner/MedicalCenters/MedicalCenterProfileController.php

namespace App\Http\Controllers\Api\Owner\MedicalCenters;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MedicalCenterProfileController extends Controller
{
    use ApiResponse;

    public function finance(Request $request)
    {
        $userId = auth()->id();
        $period = $request->input('period', 'month');
        $wallet=DB::table('wallets')->where('user_id',$userId)->first();
        $query = DB::table('wallet_transactions')->where('wallet_id', $wallet->id);

        // فیلتر زمانی
        $now = Carbon::now();
        if ($period === 'day') {
            $query->whereDate('created_at', $now->toDateString());
        } elseif ($period === 'week') {
            $query->where('created_at', '>=', $now->subDays(7));
        } elseif ($period === 'month') {
            $query->where('created_at', '>=', $now->subDays(30));
        }

        $transactions = $query->orderBy('created_at', 'desc')->get();

        // محاسبه مجموع واریزی‌ها (type = 1)
        $totalIncome = $transactions->where('type', 1)->sum('amount');

        // فرمت کردن داده‌ها برای فرانت‌اند
        $rows = $transactions->map(function ($trx) {
            return [
                'id' => $trx->id,
                'code' => 'TRX-' . $trx->id,
                'amount' => (float) $trx->amount,
                'type' => (int) $trx->type, // 1: واریز, 2: برداشت
                'description' => $trx->description ?? 'تراکنش سیستمی',
                // تغییر این خط: ارسال تاریخ میلادی استاندارد
                'date' => \Carbon\Carbon::parse($trx->created_at)->format('Y-m-d H:i:s')
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
        return $this->success(DB::table('medical_centers_info')->where('user_id', $request->medical_center_id)->first());
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'    => 'sometimes|required|string|max:255',
            'image'   => 'nullable|string|max:255',
            'lat'     => 'nullable|numeric',
            'lng'     => 'nullable|numeric',
            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('medical_centers_info')->where('user_id', $request->medical_center_id)->update($validator->validated());

        return $this->success(null, 'پروفایل مرکز درمانی بروزرسانی شد');
    }
}
