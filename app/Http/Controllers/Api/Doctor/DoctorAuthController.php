<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class DoctorAuthController extends Controller
{
    public function login(Request $request)
    {

        // ۱) اعتبارسنجی ورودی
        $validator = validator($request->all(), [
            'mobile'   => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات ورودی نامعتبر است.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        // ۲) جستجوی کاربر
        $user = User::where('phone', $validated['mobile'])->first();

        // ۳) بررسی وجود کاربر و صحت رمز (پیام یکسان برای امنیت)
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات ورود نامعتبر است.',
            ], 401);
        }

        // ۴) بررسی نقش دکتر
        if ($user->role !== 'doctor') {
            return response()->json([
                'success' => false,
                'message' => 'شما دسترسی دکتر ندارید.',
            ], 403);
        }
        if ((int)$user->status !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'حساب کاربری شما غیرفعال است.'
            ], 403);
        }
        // ۵) ساخت توکن Sanctum
        $token = $user->createToken('doctor-token', ['doctor'])->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'ورود موفقیت‌آمیز بود.',
            'data'    => [
                'user'  => $user,
                'token' => $token,
            ],
        ]);
    }

}
