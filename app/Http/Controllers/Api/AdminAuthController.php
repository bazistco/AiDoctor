<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    public function login(Request $request)
    {
        // ۱) اعتبارسنجی ورودی
        $validator = validator($request->all(), [
            'phone'   => ['required', 'string'],
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
        $user = User::where('phone', $validated['phone'])->first();

        // ۳) بررسی وجود کاربر و صحت رمز (پیام یکسان برای امنیت)
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات ورود نامعتبر است.',
            ], 401);
        }

        // ۴) بررسی نقش ادمین
        if ($user->role != 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'شما دسترسی ادمین ندارید.',
            ], 403);
        }
        // ۵) ساخت توکن Sanctum
        $token = $user->createToken('admin-token', ['admin'])->plainTextToken;

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
