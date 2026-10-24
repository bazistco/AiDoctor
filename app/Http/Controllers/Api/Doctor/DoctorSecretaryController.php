<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DoctorSecretaryController extends Controller
{
    /**
     * دریافت لیست منشی‌های پزشک
     */
    public function index(Request $request): JsonResponse
    {
        $doctorUserId = $request->user()->id;

        $secretaries = DB::table('doctor_secretaries')
            ->join('users', 'doctor_secretaries.secretary_user_id', '=', 'users.id')
            ->where('doctor_secretaries.doctor_user_id', $doctorUserId)
            ->whereNull('users.deleted_at')
            ->select([
                'doctor_secretaries.id as relationship_id',
                'users.id as id',
                'users.name',
                'users.phone',
                'users.status',
                'doctor_secretaries.created_at',
            ])
            ->orderBy('doctor_secretaries.id', 'desc')
            ->get()
            ->map(function ($sec) {
                return [
                    'id'        => (string) $sec->id,
                    'name'      => $sec->name,
                    'phone'     => $sec->phone,
                    'active'    => (int) $sec->status === 1,
                    'createdAt' => $sec->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $secretaries,
        ]);
    }

    /**
     * فعال / غیرفعال کردن دسترسی منشی
     */
    public function toggleStatus(Request $request, int|string $id): JsonResponse
    {
        $doctorUserId = $request->user()->id;

        // بررسی اینکه منشی متعلق به همین پزشک باشد
        $relation = DB::table('doctor_secretaries')
            ->where('doctor_user_id', $doctorUserId)
            ->where('secretary_user_id', $id)
            ->first();

        if (!$relation) {
            return response()->json([
                'success' => false,
                'message' => 'منشی مورد نظر برای این حساب یافت نشد.',
            ], Response::HTTP_NOT_FOUND);
        }

        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'کاربر یافت نشد.',
            ], Response::HTTP_NOT_FOUND);
        }

        $newStatus = ((int) $user->status === 1) ? 0 : 1;

        DB::table('users')->where('id', $id)->update([
            'status'     => $newStatus,
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => $newStatus === 1 ? 'حساب منشی با موفقیت فعال شد.' : 'حساب منشی غیرفعال شد.',
            'data'    => [
                'id'     => (string) $id,
                'active' => $newStatus === 1,
            ],
        ]);
    }

    /**
     * افزودن یا انتساب منشی به پزشک
     * ایجاد کاربر جدید + ساخت کیف پول + ثبت در Novu
     */
    public function store(Request $request): JsonResponse
    {
        $doctorUserId = $request->user()->id;

        $validated = $request->validate([
            'name'  => 'required|string|min:3|max:100',
            'phone' => 'required|string|regex:/^09[0-9]{9}$/',
        ], [
            'name.required'  => 'نام و نام خانوادگی الزامی است.',
            'name.min'       => 'نام باید حداقل ۳ کاراکتر باشد.',
            'phone.required' => 'شماره موبایل الزامی است.',
            'phone.regex'    => 'فرمت شماره موبایل نامعتبر است (مثال: 09123456789).',
        ]);

        // ۱. بررسی اینکه پزشک قبلاً منشی ثبت نکرده باشد
//        $existingSecretary = DB::table('doctor_secretaries')
//            ->where('doctor_user_id', $doctorUserId)
//            ->exists();
//
//        if ($existingSecretary) {
//            return response()->json([
//                'success' => false,
//                'message' => 'شما در حال حاضر یک منشی فعال دارید. برای ثبت منشی جدید، ابتدا منشی فعلی را حذف کنید.',
//            ], Response::HTTP_CONFLICT);
//        }

        $now = now();
        $isNewSecretary = false;
        $secretaryModel = null;

        $result = DB::transaction(function () use (
            $validated,
            $doctorUserId,
            $now,
            &$isNewSecretary,
            &$secretaryModel
        ) {
            $phone = $validated['phone'];
            $name = $validated['name'];

            // ۲. بررسی وجود کاربر بر اساس شماره تماس
            $user = DB::table('users')->where('phone', $phone)->first();

            if ($user) {
                // بررسی اینکه منشی پزشک دیگری نباشد
                $alreadyAssigned = DB::table('doctor_secretaries')
                    ->where('secretary_user_id', $user->id)
                    ->exists();

                if ($user->role !== 'user' or $user->role !== 'doctor_secretary') {
                    return response()->json([
                        'success' => false,
                        'message' => 'این شماره موبایل نقشی غیر از کاربر عادی دارد',
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                if ($alreadyAssigned) {
                    return response()->json([
                        'success' => false,
                        'message' => 'این شماره موبایل در حال حاضر به عنوان منشی برای پزشک دیگری ثبت شده است.',
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                // به‌روزرسانی مشخصات و تغییر نقش به منشی
                DB::table('users')->where('id', $user->id)->update([
                    'name'       => $name,
                    'role'       => 'doctor_secretary',
                    'status'     => 1,
                    'is_verify'  => 1,
                    'updated_at' => $now,
                ]);

                // در صورتی که کاربر قبلی فاقد کیف پول باشد، کیف پول برایش ساخته می‌شود
                $hasWallet = DB::table('wallets')->where('user_id', $user->id)->exists();
                if (!$hasWallet) {
                    DB::table('wallets')->insert([
                        'user_id'    => $user->id,
                        'balance'    => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                // اگر شناسه novu نداشته باشد، مدل برای فراخوانی بعد از تراکنش تنظیم می‌شود
                if (empty($user->novu_subscriber_id)) {
                    $isNewSecretary = true;
                    $secretaryModel = (object) [
                        'id'    => $user->id,
                        'phone' => $phone,
                        'name'  => $name,
                    ];
                }

                $secretaryUserId = $user->id;
            } else {
                // ۳. ایجاد حساب کاربری جدید
                $isNewSecretary = true;

                $secretaryUserId = DB::table('users')->insertGetId([
                    'name'       => $name,
                    'phone'      => $phone,
                    'role'       => 'doctor_secretary',
                    'password'   => Hash::make($phone), // احراز هویت اولیه OTP است
                    'status'     => 1,
                    'is_verify'  => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // ۴. ایجاد کیف پول برای کاربر جدید منشی
                DB::table('wallets')->insert([
                    'user_id'    => $secretaryUserId,
                    'balance'    => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // نگهداری اطلاعات برای ارسال به Novu خارج از تراکنش دیتابیس
                $secretaryModel = (object) [
                    'id'    => $secretaryUserId,
                    'phone' => $phone,
                    'name'  => $name,
                ];
            }

            // ۵. ایجاد ارتباط منشی با پزشک
            DB::table('doctor_secretaries')->insert([
                'doctor_user_id'    => $doctorUserId,
                'secretary_user_id' => $secretaryUserId,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

            return [
                'id'        => (string) $secretaryUserId,
                'name'      => $name,
                'phone'     => $phone,
                'active'    => true,
                'createdAt' => $now->toIso8601String(),
            ];
        });

        // اگر خروجی تراکنش خطای اعتبارسنجی (مانند تداخل پزشک دیگر) باشد
        if ($result instanceof JsonResponse) {
            return $result;
        }

        // ۶. ارسال و ساخت مشترک در Novu در خارج از تراکنش (جلوگیری از قفل شدن دیتابیس در زمان تاخیر شبکه)
        if ($isNewSecretary && $secretaryModel) {
            $novuSubscriberId = (string) Str::uuid();

            try {
                $novuUrl = 'http://185.222.163.113:3000/v1/subscribers';
                $novuApiKey = config('services.novu.api_key');

                $response = Http::timeout(3)->withHeaders([
                    'Authorization' => 'ApiKey ' . $novuApiKey,
                    'Content-Type'  => 'application/json',
                ])->post($novuUrl, [
                    'subscriberId' => $novuSubscriberId,
                    'phone'        => $secretaryModel->phone,
                    'firstName'    => $secretaryModel->name,
                ]);

                if ($response->successful()) {
                    DB::table('users')->where('id', $secretaryModel->id)->update([
                        'novu_subscriber_id' => $novuSubscriberId,
                        'updated_at'         => now(),
                    ]);
                } else {
                    Log::error('Novu Subscriber Creation for Secretary Failed: ' . $response->body());
                }
            } catch (\Exception $e) {
                Log::error('Novu API Exception on Secretary Creation: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'منشی با موفقیت به مطب افزوده شد.',
            'data'    => $result,
        ], Response::HTTP_CREATED);
    }

    /**
     * حذف ارتباط منشی با پزشک
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $doctorUserId = $request->user()->id;

        $deleted = DB::table('doctor_secretaries')
            ->where('doctor_user_id', $doctorUserId)
            ->where('secretary_user_id', $id)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'منشی مورد نظر یافت نشد.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'message' => 'منشی با موفقیت حذف شد.',
        ]);
    }
}
