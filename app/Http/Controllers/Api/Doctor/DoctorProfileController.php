<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DoctorProfileController extends Controller
{
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
    public function getProfile(Request $request)
    {
        $doctorId = $request->user()->id;

        $profile = DB::table('users')
            ->leftJoin('doctor_info', 'users.id', '=', 'doctor_info.user_id')
            ->leftJoin('specialties', 'doctor_info.specialty_id', '=', 'specialties.id')
            ->leftJoin('provinces', 'users.province_id', '=', 'provinces.id')
            ->leftJoin('cities', 'users.city_id', '=', 'cities.id')
            ->where('users.id', $doctorId)
            ->where('users.role', 'doctor')
            ->select(
                'users.id',
                'users.name as user_name',
                'users.email',
                'users.phone as user_phone',
                'users.gender',
                'users.avatar',
                'users.is_verify',
                'users.status',
                'provinces.name as province_name',
                'cities.name as city_name',
                'doctor_info.name as doctor_name',
                'doctor_info.bio',
                'doctor_info.visit_price',
                'doctor_info.experience',
                'doctor_info.address',
                'doctor_info.phone as office_phone',
                'doctor_info.visit_count',
                'doctor_info.rating',
                'doctor_info.image_url',
                'doctor_info.is_vip',
                'doctor_info.medical_code',
                'doctor_info.rank',
                'doctor_info.reviews',
                'doctor_info.recommendation',
                'specialties.name as specialty_name'
            )
            ->first();

        if (!$profile) {
            return response()->json(['message' => 'پروفایل یافت نشد.'], 404);
        }

        return response()->json([
            'doctor' => [
                'id' => $profile->id,
                'name' => $profile->doctor_name ?? $profile->user_name,
                'email' => $profile->email,
                'phone' => $profile->user_phone,
                'office_phone' => $profile->office_phone,
                'gender' => $profile->gender == 1 ? 'مرد' : 'زن',
                'avatar' => $profile->avatar,
                'image_url' => $profile->image_url,
                'is_verify' => (bool) $profile->is_verify,
                'is_vip' => (bool) $profile->is_vip,
                'status' => $profile->status,
                'bio' => $profile->bio,
                'specialty' => $profile->specialty_name,
                'visit_price' => (int) $profile->visit_price,
                'experience' => $profile->experience,
                'address' => $profile->address,
                'province' => $profile->province_name,
                'city' => $profile->city_name,
                'visit_count' => (int) $profile->visit_count,
                'rating' => (float) $profile->rating,
                'medical_code' => $profile->medical_code,
                'rank' => $profile->rank,
                'reviews' => (int) $profile->reviews,
                'recommendation' => (int) $profile->recommendation,
            ]
        ]);
    }
}
