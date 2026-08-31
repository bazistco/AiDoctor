<?php
// app/Http/Controllers/Owner/Labs/LabProfileController.php

namespace App\Http\Controllers\Api\Owner\Labs;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LabProfileController extends Controller
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
        $labId = $request->lab_id; // میدلور ownership این رو ست کرده
        $lab = DB::table('labs_info')->where('user_id', $labId)->first();

        return $this->success($lab);
    }
    public function toggleStatus(Request $request)
    {
        // دریافت شناسه کاربر احراز‌شده
        $userId = auth()->id();
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // یافتن آزمایشگاه مرتبط با این کاربر
        $lab = DB::table('labs_info')->where('user_id', $userId)->first();

        if (!$lab) {
            return response()->json(['message' => 'آزمایشگاه یافت نشد'], 404);
        }

        // تغییر وضعیت (۰ ← ۱ یا برعکس)
        $newStatus = $lab->status == 1 ? 0 : 1;

        // به‌روزرسانی در دیتابیس
        DB::table('labs_info')
            ->where('user_id', $userId)
            ->update(['status' => $newStatus]);

        // بازگرداندن وضعیت جدید به کلاینت
        return response()->json([
            'status' => $newStatus,
            'message' => 'وضعیت با موفقیت تغییر کرد',
            'isActive' => (bool) $newStatus // برای استفاده مستقیم در فرانت‌اند
        ]);
    }
    public function update(Request $request)
    {
        $labId = $request->lab_id;

        $validator = Validator::make($request->all(), [
            'name'              => 'sometimes|required|string|max:255',
            'license_number'    => 'nullable|string|max:255',
            'technical_manager' => 'nullable|string|max:255',
            'work_hours'        => 'nullable|string|max:255',
            'address'           => 'nullable|string',
            'status'            => 'nullable|integer|in:0,1',
            'min_order_amount'  => 'nullable|integer',
            'image'             => 'nullable|string|max:255',
            'lat'               => 'nullable|numeric',
            'lng'               => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('labs_info')->where('user_id', $labId)->update($validator->validated());

        return $this->success(null, 'پروفایل آزمایشگاه بروزرسانی شد');
    }

}
