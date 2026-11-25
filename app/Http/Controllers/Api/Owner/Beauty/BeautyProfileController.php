<?php

namespace App\Http\Controllers\Api\Owner\Beauty;

use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BeautyProfileController extends \App\Http\Controllers\Controller
{

    use ApiResponse;

    public function update(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'phone'       => 'nullable|string|max:30',
            'address'     => 'nullable|string|max:1000',
            'work_hours'  => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'lat'         => 'nullable|numeric',
            'lng'         => 'nullable|numeric',
            'status'      => 'nullable|integer|in:0,1',
            'image'       => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $clinicId = (int) ($request->input('beauty_clinic_id') ?? auth()->id());

        if (!$clinicId) {
            return response()->json([
                'status'  => false,
                'message' => 'شناسه کلینیک زیبایی یافت نشد.',
            ], 404);
        }

        $existing = DB::table('beauty_clinic_info')
            ->where('user_id', $clinicId)
            ->first();

        $imagePath        = $existing->image ?? null;
        $isAvatarVerified = (int) ($existing->is_avatar_verified ?? 0);

        if ($request->hasFile('image')) {
            $imagePath        = $request->file('image')->store('beauty_clinics', 'public');
            $isAvatarVerified = 0; // هنگام آپلود عکس جدید، در انتظار تایید قرار می‌گیرد
        }

        $payload = [
            'name'               => trim($request->input('name')),
            'phone'              => $request->input('phone', $existing->phone ?? null),
            'address'            => $request->input('address', $existing->address ?? null),
            'work_hours'         => $request->input('work_hours', $existing->work_hours ?? null),
            'description'        => $request->input('description', $existing->description ?? null),
            'lat'                => $request->input('lat', $existing->lat ?? null),
            'lng'                => $request->input('lng', $existing->lng ?? null),
            'image'              => $imagePath,
            'is_avatar_verified' => $isAvatarVerified,
            'status'             => $request->has('status')
                ? (int) $request->input('status')
                : (int) ($existing->status ?? 1),
            'updated_at'         => now(),
        ];

        DB::transaction(function () use ($clinicId, $existing, $payload) {
            $infoData = $payload;
            unset($infoData['name']); // نام در جدول users ذخیره می‌شود

            if ($existing) {
                DB::table('beauty_clinic_info')
                    ->where('user_id', $clinicId)
                    ->update($infoData);
            } else {
                $slugBase = \Illuminate\Support\Str::slug($payload['name']) ?: 'beauty-clinic';
                $infoData['user_id']    = $clinicId;
                $infoData['slug']       = "{$slugBase}-{$clinicId}";
                $infoData['created_at'] = now();

                DB::table('beauty_clinic_info')->insert($infoData);
            }

            DB::table('users')
                ->where('id', $clinicId)
                ->update([
                    'name'       => $payload['name'],
                    'updated_at' => now(),
                ]);
        });

        $defaultImageUrl = 'https://api.mediraai.com/clinic.jpg';
        $uploadedImageUrl = !empty($imagePath)
            ? (str_starts_with($imagePath, 'http')
                ? $imagePath
                : 'https://api.mediraai.com/storage/' . ltrim($imagePath, '/'))
            : null;

        $publicImageUrl = ($isAvatarVerified === 1 && $uploadedImageUrl)
            ? $uploadedImageUrl
            : $defaultImageUrl;

        return $this->success(array_merge($payload, [
            'user_id'            => $clinicId,
            'image_url'          => $publicImageUrl,   // خروجی عمومی برای نمایش در سایت (با در نظر گرفتن تایید)
            'raw_image_url'      => $uploadedImageUrl,  // تصویر واقعی آپلودشده برای پنل مدیریت خود کلینیک/ادمین
            'default_image_url'  => $defaultImageUrl,
        ]), 'پروفایل کلینیک زیبایی با موفقیت بروزرسانی شد.');
    }

    public function show(Request $request)
    {
        $beautyClinicId = $request->beauty_clinic_id;

        $lab = DB::table('beauty_clinic_info')
            ->where('user_id', $beautyClinicId)
            ->first();

        if (!$lab) {
            return $this->success(null);
        }

        $defaultImageUrl = 'https://api.mediraai.com/clinic.jpg';
        $uploadedImageUrl = !empty($lab->image)
            ? (str_starts_with($lab->image, 'http')
                ? $lab->image
                : 'https://api.mediraai.com/storage/' . ltrim($lab->image, '/'))
            : null;

        $isVerified = (int) ($lab->is_avatar_verified ?? 0) === 1;

        $data = (array) $lab;
        $data['is_avatar_verified'] = (int) ($lab->is_avatar_verified ?? 0);
        $data['image_url'] = ($isVerified && $uploadedImageUrl)
            ? $uploadedImageUrl
            : $defaultImageUrl;
        $data['raw_image_url'] = $uploadedImageUrl;

        return $this->success($data);
    }
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

}
