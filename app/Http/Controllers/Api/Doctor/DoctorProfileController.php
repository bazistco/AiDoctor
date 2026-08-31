<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DoctorProfileController extends Controller
{
    public function updateProfile(Request $request)
    {
        $userId = $request->user()->id;

        // اعتبارسنجی داده‌های ورودی (اضافه شدن avatar)
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'gender' => 'nullable|in:0,1',
            'province_id' => 'nullable|integer|exists:provinces,id',
            'city_id'     => 'nullable|integer|exists:cities,id',
            'bio' => 'nullable|string',
            'address' => 'nullable|string',
            'office_phone' => 'nullable|string|max:20',
            'visit_price' => 'nullable|numeric',
            'phone_consultation_price' => 'nullable|numeric',
            'video_consultation_price' => 'nullable|numeric',
            'medical_code' => 'nullable|string|max:50',
            'experience' => 'nullable|integer',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096', // حداکثر 4 مگابایت
        ]);

        try {
            DB::beginTransaction();

            // آپدیت جدول users
            $userData = [];
            if ($request->has('name')) $userData['name'] = $validated['name'];
            if ($request->has('email')) $userData['email'] = $validated['email'];
            if ($request->has('gender')) $userData['gender'] = $validated['gender'];
            if ($request->has('province_id')) $userData['province_id'] = $validated['province_id'];
            if ($request->has('city_id')) $userData['city_id'] = $validated['city_id'];

            if (!empty($userData)) {
                DB::table('users')->where('id', $userId)->update($userData);
            }

            // آپدیت جدول doctor_info
            $doctorData = [];
            if ($request->has('name')) $doctorData['name'] = $validated['name'];
            if ($request->has('bio')) $doctorData['bio'] = $validated['bio'];
            if ($request->has('address')) $doctorData['address'] = $validated['address'];
            if ($request->has('office_phone')) $doctorData['phone'] = $validated['office_phone'];
            if ($request->has('visit_price')) $doctorData['visit_price'] = $validated['visit_price'];
            if ($request->has('phone_consultation_price')) $doctorData['phone_consultation_price'] = $validated['phone_consultation_price'];
            if ($request->has('video_consultation_price')) $doctorData['video_consultation_price'] = $validated['video_consultation_price'];
            if ($request->has('medical_code')) $doctorData['medical_code'] = $validated['medical_code'];
            if ($request->has('experience')) $doctorData['experience'] = $validated['experience'];

            // هندل کردن آپلود عکس
            if ($request->hasFile('avatar')) {
                // آپلود در پوشه storage/app/public/avatars
                $path = $request->file('avatar')->store('avatars', 'public');

                // در اینجا فرض شده ستون عکس در دیتابیس image_url است
                // اگر نام ستون در دیتابیس شما چیز دیگریست (مثل image یا avatar) آن را تغییر دهید
                $doctorData['image_url'] = $path;
            }

            if (!empty($doctorData)) {
                DB::table('doctor_info')->where('user_id', $userId)->update($doctorData);
            }

            DB::commit();

            return response()->json([
                'status' => 200,
                'message' => 'پروفایل با موفقیت بروزرسانی شد.',
                // لینک عکس آپلود شده برای آپدیت درجا در فرانت در صورت نیاز
                'avatar_url' => isset($path) ? asset('storage/' . $path) : null
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 500,
                'message' => 'خطا در بروزرسانی پروفایل.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function finance(Request $request)
    {
        $userId = auth()->id();
        $period = $request->input('period', 'month');

        $wallet = DB::table('wallets')->where('user_id', $userId)->first();

        if (!$wallet) {
            return response()->json(['status' => 404, 'message' => 'کیف پول یافت نشد'], 404);
        }

        $now = Carbon::now();

        $query = DB::table('wallet_transactions as wt')
            ->leftJoin('orders as o', function ($join) {
                $join->on('o.id', '=', 'wt.subject_id')
                    ->where('o.reason_id', '=', 1)
                    ->where('wt.subject_type', '=', 2);
            })
            ->leftJoin('appointment_slots as ap', 'ap.id', '=', 'o.reason_ref')
            ->leftJoin('users as u', 'u.id', '=', 'ap.patient_id')
            ->where('wt.wallet_id', $wallet->id)
            ->select(
                'wt.id',
                'wt.amount',
                'wt.type',
                'wt.created_at',
                'wt.description',
                'o.reason_ref',
                'u.name',
                'u.phone'
            );

        if ($period === 'day') {
            $query->whereDate('wt.created_at', $now->toDateString());
        } elseif ($period === 'week') {
            $query->where('wt.created_at', '>=', $now->copy()->subDays(7));
        } elseif ($period === 'month') {
            $query->where('wt.created_at', '>=', $now->copy()->subDays(30));
        }

        $transactions = $query->orderBy('wt.created_at', 'desc')->get();

        $totalIncome = $transactions->where('type', 1)->sum('amount');

        $rows = $transactions->map(function ($trx) {
            return [
                'id'           => $trx->id,
                'code'         => 'TRX-' . $trx->id,
                'amount'       => (float) $trx->amount,
                'type'         => (int) $trx->type,
                'description'  => $trx->description ?? 'تراکنش سیستمی',
                'date'         => Carbon::parse($trx->created_at)->format('Y-m-d H:i:s'),
                'reason_ref'   => $trx->reason_ref,
                'patientName'  => $trx->name,
                'patientPhone' => $trx->phone,
            ];
        })->values();

        return response()->json([
            'status' => 200,
            'data'   => [
                'balance'     => $wallet->balance,
                'totalIncome' => $totalIncome,
                'rows'        => $rows,
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
                'users.province_id',
                'users.city_id',
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
                'doctor_info.phone_consultation_price',
                'doctor_info.video_consultation_price',
                'specialties.name as specialty_name'
            )
            ->first();

        if (!$profile) {
            return response()->json(['message' => 'پروفایل یافت نشد.'], 404);
        }
        if ($profile) {
            // تبدیل مسیر نسبی دیتابیس به لینک کامل (URL)
            if (!empty($profile->image_url)) {
                $profile->image_url = asset('storage/' . $profile->image_url);
            }
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
                'province_id' => $profile->province_id,
                'city_id' => $profile->city_id,
                'province' => $profile->province_name,
                'city' => $profile->city_name,
                'visit_count' => (int) $profile->visit_count,
                'rating' => (float) $profile->rating,
                'medical_code' => $profile->medical_code,
                'rank' => $profile->rank,
                'reviews' => (int) $profile->reviews,
                'recommendation' => (int) $profile->recommendation,
                'phone_consultation_price'=>(int) $profile->phone_consultation_price,
                'video_consultation_price'=>(int) $profile->video_consultation_price,
            ]
        ]);
    }
}
