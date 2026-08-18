<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class TourController extends Controller
{
    public function show($id)
    {
        $tourDataJson = Redis::get("tour:data:{$id}");

        if (!$tourDataJson) {
            return response()->json(['error' => 'تور یافت نشد.'], 404);
        }

        $tourData = json_decode($tourDataJson, true);

        // واکشی لیست شرکت‌کنندگان واقعی
        $mobiles = Redis::smembers("tour:participants:{$id}");
        $participants = [];
        foreach ($mobiles as $mob) {
            $detailsJson = Redis::get("tour:participant:{$id}:{$mob}");
            $details = $detailsJson ? json_decode($detailsJson, true) : [];
            $participants[] = [
                'name' => !empty($details['name']) ? $details['name'] : 'همسفر ناشناس',
                'avatar' => 'https://api.dicebear.com/7.x/adventurer/svg?seed=' . $mob // یک آواتار فان و تصادفی بر اساس موبایل
            ];
        }

        $tourData['participantsList'] = $participants;

        // حذف رمز عبور از دیتای ارسالی به کلاینت (فقط می‌گوییم خصوصی است یا نه)
        $isPrivate = !empty($tourData['isPrivate']) && $tourData['isPrivate'] == true;
        unset($tourData['password']);
        $tourData['isPrivate'] = $isPrivate;

        return response()->json(['data' => $tourData]);
    }

    public function join(Request $request, $id)
    {
        $mobile = $request->input('mobile');
        $password = $request->input('password');

        if (!$mobile) {
            return response()->json(['error' => 'شماره موبایل الزامی است.'], 400);
        }

        $tourDataJson = Redis::get("tour:data:{$id}");

        if (!$tourDataJson) {
            return response()->json(['error' => 'تور یافت نشد.'], 404);
        }

        $tourData = json_decode($tourDataJson, true);

        // بررسی اسم شب برای تورهای خصوصی
        if (!empty($tourData['isPrivate']) && $tourData['isPrivate'] == true) {
            if (empty($password) || $password !== $tourData['password']) {
                return response()->json(['error' => 'اسم شب اشتباهه! اجازه ورود نداری 🕵️‍♂️'], 403);
            }
        }

        $isAlreadyJoined = Redis::sismember("tour:participants:{$id}", $mobile);

        if ($isAlreadyJoined) {
            return response()->json(['message' => 'شما قبلاً در این تور ثبت‌نام کرده‌اید.', 'data' => $tourData], 200);
        }

        // افزایش تعداد ثبت‌نامی‌ها
        $joined = (int)($tourData['joined'] ?? 0);
        $tourData['joined'] = $joined + 1;
        Redis::set("tour:data:{$id}", json_encode($tourData));

        Redis::sadd("tour:participants:{$id}", $mobile);
        Redis::sadd("user:joined_tours:{$mobile}", $id);

        return response()->json([
            'message' => 'اسم شب درست بود! به جمع ما خوش اومدی 🎉',
            'data' => $tourData
        ], 200);
    }

    /**
     * دریافت لیست تورهای یک لیدر (بر اساس شماره موبایل)
     */
    public function index(Request $request)
    {
        $mobile = $request->query('mobile');

        if (!$mobile) {
            return response()->json(['error' => 'شماره موبایل الزامی است.'], 400);
        }

        // دریافت لیست ID تورهای این موبایل
        $tourIds = Redis::smembers("tour:user:{$mobile}");
        $tours = [];

        foreach ($tourIds as $id) {
            $tourData = Redis::get("tour:data:{$id}");
            if ($tourData) {
                $tours[] = json_decode($tourData, true);
            }
        }

        // مرتب‌سازی نزولی (جدیدترین‌ها اول)
        $tours = collect($tours)->sortByDesc('created_at')->values()->all();

        return response()->json(['data' => $tours]);
    }

    /**
     * ساخت تور جدید
     */
    public function store(Request $request)
    {
        $mobile = $request->input('mobile');

        if (!$mobile) {
            return response()->json(['error' => 'شماره موبایل الزامی است.'], 400);
        }

        $tourId = uniqid('t_');

        // دریافت داده‌های فرم
        $tourData = $request->except(['mobile', 'coverImage', 'leaderAvatar']);
        // دریافت وضعیت خصوصی بودن و اسم شب
        $tourData['isPrivate'] = $request->input('isPrivate', false) === 'true' || $request->input('isPrivate') === true;
        $tourData['password'] = $request->input('password', '');

        $tourData['id'] = $tourId;
        $tourData['joined'] = 0;
        $tourData['status'] = 'active';
        $tourData['created_at'] = now()->toDateTimeString();

        // از آنجا که ایتینراری ممکن است به صورت رشته JSON ارسال شود، آن را به آرایه تبدیل می‌کنیم
        if (isset($tourData['itinerary']) && is_string($tourData['itinerary'])) {
            $tourData['itinerary'] = json_decode($tourData['itinerary'], true);
        }

        // 1. آپلود تصویر کاور
        if ($request->hasFile('coverImage')) {
            $path = $request->file('coverImage')->store('tours/covers', 'public');
            $tourData['coverImage'] = asset('storage/' . $path);
        } else {
            $tourData['coverImage'] = $request->input('coverImage', '');
        }

        // 2. آپلود آواتار لیدر
        if ($request->hasFile('leaderAvatar')) {
            $path = $request->file('leaderAvatar')->store('tours/avatars', 'public');
            $tourData['leaderAvatar'] = asset('storage/' . $path);
        } else {
            $tourData['leaderAvatar'] = $request->input('leaderAvatar', '');
        }

        // ذخیره دیتای اصلی تور
        Redis::set("tour:data:{$tourId}", json_encode($tourData));

        // افزودن ID این تور به لیست تورهای لیدر
        Redis::sadd("tour:user:{$mobile}", $tourId);

        return response()->json([
            'message' => 'تور با موفقیت ایجاد شد.',
            'data' => $tourData
        ], 201);
    }

    /**
     * دریافت لیست شرکت‌کنندگان یک تور همراه با اطلاعات تکمیلی
     */
    public function getParticipants($id)
    {
        $mobiles = Redis::smembers("tour:participants:{$id}");
        $participants = [];

        foreach ($mobiles as $mobile) {
            $detailsJson = Redis::get("tour:participant:{$id}:{$mobile}");

            if ($detailsJson) {
                $details = json_decode($detailsJson, true);
                $participants[] = array_merge(['mobile' => $mobile], $details);
            } else {
                $participants[] = [
                    'mobile' => $mobile,
                    'name' => '',
                    'paymentStatus' => 'unpaid',
                    'trackingCode' => ''
                ];
            }
        }

        return response()->json(['data' => $participants], 200);
    }

    /**
     * به‌روزرسانی اطلاعات تکمیلی یک مسافر (توسط لیدر)
     */
    public function updateParticipant(Request $request, $id)
    {
        $mobile = $request->input('mobile');

        if (!$mobile) {
            return response()->json(['error' => 'شماره موبایل مسافر الزامی است.'], 400);
        }

        $isJoined = Redis::sismember("tour:participants:{$id}", $mobile);
        if (!$isJoined) {
            return response()->json(['error' => 'این مسافر در تور ثبت‌نام نکرده است.'], 404);
        }

        $details = [
            'name' => $request->input('name', ''),
            'paymentStatus' => $request->input('paymentStatus', 'unpaid'),
            'trackingCode' => $request->input('trackingCode', '')
        ];

        Redis::set("tour:participant:{$id}:{$mobile}", json_encode($details));

        return response()->json([
            'message' => 'اطلاعات مسافر با موفقیت به‌روزرسانی شد.',
            'data' => array_merge(['mobile' => $mobile], $details)
        ], 200);
    }

    /**
     * حذف مسافر از تور (اخراج به دلیل عدم پرداخت و ...)
     */
    public function removeParticipant(Request $request, $id, $mobile)
    {
        // 1. بررسی حضور در تور
        $isJoined = Redis::sismember("tour:participants:{$id}", $mobile);
        if (!$isJoined) {
            return response()->json(['error' => 'این مسافر در تور یافت نشد.'], 404);
        }

        // 2. حذف از لیست مسافران تور
        Redis::srem("tour:participants:{$id}", $mobile);

        // 3. پاک کردن اطلاعات تکمیلی ذخیره شده
        Redis::del("tour:participant:{$id}:{$mobile}");

        // 4. حذف از لیست تورهای کاربر (در صورت وجود)
        Redis::srem("user:joined_tours:{$mobile}", $id);

        // 5. کاهش دادن ظرفیت ثبت‌نامی‌ها
        $tourDataJson = Redis::get("tour:data:{$id}");
        if ($tourDataJson) {
            $tourData = json_decode($tourDataJson, true);
            $joined = (int)($tourData['joined'] ?? 1);
            $tourData['joined'] = max(0, $joined - 1);
            Redis::set("tour:data:{$id}", json_encode($tourData));
        }

        return response()->json([
            'message' => 'مسافر با موفقیت از تور حذف شد.'
        ], 200);
    }
}
