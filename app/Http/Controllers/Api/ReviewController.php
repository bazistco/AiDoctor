<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class ReviewController extends Controller
{
    public function getProviderReviews(Request $request)
    {
        $providerId =auth()->user()->id;

        $reviews = DB::table('reviews')
            ->where('provider_id', $providerId)
            ->leftJoin('users', 'reviews.user_id', '=', 'users.id')
            ->select(
                'reviews.id',
                'reviews.rating',
                'reviews.comment',
                'reviews.created_at as date',
                DB::raw("users.name AS patientName")
            )
            ->orderByDesc('reviews.id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $reviews
        ], 200);
    }

    public function storeReview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|integer',
            'order_type' => 'required|in:doctor,lab,pharmacy,nurse',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات ارسالی نامعتبر است.',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = $request->user()->id;
        $orderId = $request->input('order_id');
        $orderType = $request->input('order_type');

        $isCompleted = false;
        $orderExists = false;
        $providerId = null;

        switch ($orderType) {
            case 'lab':
                $order = DB::table('users_labs_requests')
                    ->where('id', $orderId)
                    ->where('user_id', $userId)
                    ->first();
                if ($order) {
                    $orderExists = true;
                    $providerId = $order->lab_id; // ستون شناسه آزمایشگاه در جدول
                    if (in_array($order->status, [4, 5])) {
                        $isCompleted = true;
                    }
                }
                break;

            case 'pharmacy':
                $order = DB::table('users_pharmacy_requests')
                    ->where('id', $orderId)
                    ->where('user_id', $userId)
                    ->first();
                if ($order) {
                    $orderExists = true;
                    $providerId = $order->pharmacy_id; // ستون شناسه داروخانه
                    if (in_array($order->status, [5, 6])) {
                        $isCompleted = true;
                    }
                }
                break;

            case 'nurse':
                $order = DB::table('user_medical_center_requests')
                    ->where('id', $orderId)
                    ->where('user_id', $userId)
                    ->first();
                if ($order) {
                    $orderExists = true;
                    $providerId = $order->medical_center_id; // ستون شناسه پرستار
                    if ($order->status == 4) {
                        $isCompleted = true;
                    }
                }
                break;

            case 'doctor':
                $order = DB::table('appointment_slots')
                    ->where('id', $orderId)
                    ->where('patient_id', $userId)
                    ->first();
                if ($order) {
                    $orderExists = true;
                    $providerId = $order->doctor_id; // ستون شناسه پزشک
                    if ($order->status === 'completed' || $order->status == 3 || $order->status == 'done') {
                        $isCompleted = true;
                    }
                }
                break;
        }

        if (!$orderExists) {
            return response()->json([
                'success' => false,
                'message' => 'سفارش مورد نظر یافت نشد یا متعلق به شما نیست.'
            ], 404);
        }

        if (!$isCompleted) {
            return response()->json([
                'success' => false,
                'message' => 'ثبت نظر تنها برای درخواست‌های تکمیل‌شده امکان‌پذیر است.'
            ], 403);
        }

        if (!$providerId) {
            return response()->json([
                'success' => false,
                'message' => 'شناسه سرویس‌دهنده برای این سفارش یافت نشد.'
            ], 400);
        }

        $existingReview = DB::table('reviews')
            ->where('order_type', $orderType)
            ->where('order_id', $orderId)
            ->first();

        if ($existingReview) {
            return response()->json([
                'success' => false,
                'message' => 'شما قبلاً برای این درخواست نظر خود را ثبت کرده‌اید.'
            ], 400);
        }

        // ثبت نظر با شناسه سرویس‌دهنده
        DB::table('reviews')->insert([
            'user_id' => $userId,
            'provider_id' => $providerId,
            'order_id' => $orderId,
            'order_type' => $orderType,
            'rating' => $request->input('rating'),
            'comment' => $request->input('comment'),
            'created_at' => Carbon::now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'نظر شما با موفقیت ثبت شد.'
        ], 201);
    }
}
