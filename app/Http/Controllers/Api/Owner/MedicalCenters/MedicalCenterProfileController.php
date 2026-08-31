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
        $query = DB::table('wallet_transactions')
            ->leftJoin('orders', function ($join) {
                $join->on('wallet_transactions.subject_id', '=', 'orders.id')->where('wallet_transactions.type', '=', 1)
                    ->where('wallet_transactions.subject_type', '=', 2)
                    ->where('orders.reason_id', '=', 4);
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
    public function dashboard(Request $request)
    {
        $userId = $request->user()->id;

        // استخراج نام و وضعیت از جدول medical_centers_info
        $center = DB::table('medical_centers_info')
            ->where('user_id', $userId)
            ->select('name', 'status')
            ->first();

        if (!$center) {
            return response()->json([
                'status'  => 404,
                'message' => 'اطلاعات مرکز درمانی یافت نشد'
            ], 404);
        }

        // چارت ۷ روز اخیر با مقادیر پیش‌فرض 0
        $chartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $chartData[] = [
                'day'   => $date->locale('fa')->isoFormat('dddd'),
                'date'  => $date->toDateString(),
                'count' => 0,
            ];
        }

        return response()->json([
            'status' => 200,
            'data'   => [
                'profile' => [
                    'name'        => $center->name ?? 'مرکز درمانی',
                    'isAvailable' => (bool) ($center->status == 1),
                    'status'      => (int) ($center->status ?? 0),
                    'rating'      => 0,
                ],
                'stats' => [
                    'newCount'           => 0,
                    'todayVisits'        => 0,
                    'completedThisMonth' => 0,
                    'revenue'            => 0,
                ],
                'chartData'      => $chartData,
                'recentRequests' => [],
            ]
        ], 200);
    }

    /**
     * تاگل وضعیت (0 / 1) فیلد status در جدول medical_centers_info
     */
    public function toggleStatus(Request $request)
    {
        $userId = $request->user()->id;

        $center = DB::table('medical_centers_info')
            ->where('user_id', $userId)
            ->first();

        if (!$center) {
            return response()->json([
                'status'  => 404,
                'message' => 'اطلاعات مرکز درمانی یافت نشد'
            ], 404);
        }

        // اگر مقدار status مستقیماً ارسال شده بود اعمال شود، در غیر این صورت تاگل (معکوس) شود
        if ($request->has('status')) {
            $newStatus = filter_var($request->input('status'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        } else {
            $newStatus = ($center->status == 1) ? 0 : 1;
        }

        DB::table('medical_centers_info')
            ->where('user_id', $userId)
            ->update([
                'status'     => $newStatus,
                'updated_at' => now(),
            ]);

        return response()->json([
            'status'  => 200,
            'message' => $newStatus == 1 ? 'وضعیت با موفقیت فعال شد.' : 'وضعیت با موفقیت غیرفعال شد.',
            'data'    => [
                'status'      => $newStatus,
                'isAvailable' => (bool) ($newStatus == 1),
            ]
        ], 200);
    }
}
