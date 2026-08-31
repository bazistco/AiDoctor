<?php
// app/Http/Controllers/Owner/Pharmacies/PharmacyProfileController.php

namespace App\Http\Controllers\Api\Owner\Pharmacies;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PharmacyProfileController extends Controller
{
    use ApiResponse;

    public function dashboard(Request $request)
    {
        $userId = $request->user()->id;

        // دریافت رکورد داروخانه کاربر جاری
        $pharmacy = DB::table('pharmacies_info')
            ->where('user_id', $userId)
            ->select('name', 'status')
            ->first();

        if (!$pharmacy) {
            return response()->json([
                'status'  => 404,
                'message' => 'اطلاعات داروخانه یافت نشد'
            ], 404);
        }

        // چارت ۷ روز اخیر با مقادیر صفر
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
                    'pharmacyName' => $pharmacy->name ?? 'داروخانه',
                    'status'       => (int) ($pharmacy->status ?? 0),
                    'isOpen'       => (bool) ($pharmacy->status == 1),
                    'rating'       => 0,
                    'avgPrepTime'  => '۰ دقیقه',
                ],
                'stats' => [
                    'newCount'  => 0,
                    'preparing' => 0,
                    'ready'     => 0,
                    'revenue'   => 0,
                ],
                'chartData'      => $chartData,
                'recentRequests' => [],
            ]
        ], 200);
    }

    /**
     * تاگل وضعیت باز/بسته (1/0) فیلد status در جدول pharmacies_info
     */
    public function toggleStatus(Request $request)
    {
        $userId = $request->user()->id;

        $pharmacy = DB::table('pharmacies_info')
            ->where('user_id', $userId)
            ->first();

        if (!$pharmacy) {
            return response()->json([
                'status'  => 404,
                'message' => 'اطلاعات داروخانه یافت نشد'
            ], 404);
        }

        if ($request->has('status')) {
            $newStatus = filter_var($request->input('status'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        } else {
            $newStatus = ($pharmacy->status == 1) ? 0 : 1;
        }

        DB::table('pharmacies_info')
            ->where('user_id', $userId)
            ->update([
                'status'     => $newStatus,
                'updated_at' => now(),
            ]);

        return response()->json([
            'status'  => 200,
            'message' => $newStatus == 1 ? 'وضعیت داروخانه به "باز" تغییر یافت.' : 'وضعیت داروخانه به "بسته" تغییر یافت.',
            'data'    => [
                'status' => $newStatus,
                'isOpen' => (bool) ($newStatus == 1),
            ]
        ], 200);
    }

    public function show(Request $request)
    {
        return $this->success(DB::table('pharmacies_info')->where('id', $request->pharmacy_id)->first());
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

        DB::table('pharmacies_info')->where('id', $request->pharmacy_id)->update($validator->validated());

        return $this->success(null, 'پروفایل داروخانه بروزرسانی شد');
    }
}
