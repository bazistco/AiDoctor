<?php

namespace App\Http\Controllers\Api\Owner\Labs;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LabStaffController extends Controller
{
    /**
     * واکشی شناسه مرکز آزمایشگاه (center_id) متعلق به کاربر لاگین‌شده
     */
    private function getLabCenterId(Request $request): ?int
    {
        $user = $request->user();

        // در صورتی که مرکز مستقیماً به کاربر آزمایشگاه متصل باشد
        $lab = DB::table('labs_info')
            ->where('user_id', $user->id)
            ->first();

        if ($lab) {
            return (int) $lab->user_id;
        }

//        // بررسی اینکه اگر جدول آزمایشگاه‌ها labs باشد
//        $legacyLab = DB::table('labs')->where('user_id', $user->id)->first();
//        return $legacyLab ? (int) $legacyLab->id : null;
    }

    /**
     * دریافت لیست کارمندان آزمایشگاه
     */
    public function index(Request $request): JsonResponse
    {
        $centerId = $this->getLabCenterId($request);
        if (!$centerId) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات مرکز آزمایشگاهی برای این حساب کاربری یافت نشد.',
            ], Response::HTTP_NOT_FOUND);
        }

        $staffs = DB::table('medical_centers_staffs')
            ->join('users', 'medical_centers_staffs.staff_id', '=', 'users.id')
            ->where('medical_centers_staffs.center_id', $centerId)
            ->where('medical_centers_staffs.center_type', 'lab')
            ->whereNull('users.deleted_at')
            ->select([
                'medical_centers_staffs.id as relation_id',
                'users.id as id',
                'users.name',
                'users.phone',
                'medical_centers_staffs.job_title',
                'medical_centers_staffs.is_active',
                'medical_centers_staffs.created_at',
            ])
            ->orderBy('medical_centers_staffs.id', 'desc')
            ->get()
            ->map(function ($staff) {
                return [
                    'id'        => (string) $staff->id,
                    'name'      => $staff->name,
                    'phone'     => $staff->phone,
                    'jobTitle'  => $staff->job_title ?? 'کارمند آزمایشگاه',
                    'active'    => (int) $staff->is_active === 1,
                    'createdAt' => $staff->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $staffs,
        ]);
    }

    /**
     * فعال / غیرفعال‌سازی دسترسی کارمند در مرکز
     */
    public function toggleStatus(Request $request, int|string $id): JsonResponse
    {
        $centerId = $this->getLabCenterId($request);
        if (!$centerId) {
            return response()->json([
                'success' => false,
                'message' => 'مرکز آزمایشگاه یافت نشد.',
            ], Response::HTTP_NOT_FOUND);
        }

        $relation = DB::table('medical_centers_staffs')
            ->where('center_id', $centerId)
            ->where('staff_id', $id)
            ->where('center_type', 'lab')
            ->first();

        if (!$relation) {
            return response()->json([
                'success' => false,
                'message' => 'کارمند در این آزمایشگاه یافت نشد.',
            ], Response::HTTP_NOT_FOUND);
        }

        $newActive = ((int) $relation->is_active === 1) ? 0 : 1;

        DB::table('medical_centers_staffs')
            ->where('id', $relation->id)
            ->update([
                'is_active'  => $newActive,
                'updated_at' => now(),
            ]);

        // همچنین می‌توان وضعیت کلی کاربر را هم سینک کرد
        DB::table('users')->where('id', $id)->update([
            'status'     => $newActive,
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => $newActive === 1 ? 'دسترسی کارمند با موفقیت فعال شد.' : 'دسترسی کارمند غیرفعال شد.',
            'data'    => [
                'id'     => (string) $id,
                'active' => $newActive === 1,
            ],
        ]);
    }

    /**
     * افزودن کارمند جدید به آزمایشگاه
     */
    public function store(Request $request): JsonResponse
    {
        $centerId = $this->getLabCenterId($request);
        if (!$centerId) {
            return response()->json([
                'success' => false,
                'message' => 'شناسه آزمایشگاه شما مشخص نیست.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validated = $request->validate([
            'name'      => 'required|string|min:3|max:100',
            'phone'     => 'required|string|regex:/^09[0-9]{9}$/',
            'job_title' => 'nullable|string|max:100',
        ], [
            'name.required'  => 'نام و نام خانوادگی الزامی است.',
            'name.min'       => 'نام باید حداقل ۳ کاراکتر باشد.',
            'phone.required' => 'شماره موبایل الزامی است.',
            'phone.regex'    => 'فرمت شماره موبایل نامعتبر است (مثال: 09123456789).',
        ]);

        $now = now();
        $isNewStaff = false;
        $staffModel = null;
        $jobTitle = $validated['job_title'] ?? 'کارمند و نمونه‌گیر آزمایشگاه';

        $result = DB::transaction(function () use (
            $validated,
            $centerId,
            $now,
            $jobTitle,
            &$isNewStaff,
            &$staffModel
        ) {
            $phone = $validated['phone'];
            $name  = $validated['name'];

            // ۱. بررسی کاربر با این شماره
            $user = DB::table('users')->where('phone', $phone)->first();

            if ($user) {
                // بررسی اینکه نقش مجاز باشد
                if (!in_array($user->role, ['user', 'lab_staff'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'این شماره موبایل متعلق به نقشی غیر از کارمند آزمایشگاه یا کاربر عادی است.',
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                // بررسی انتساب قبلی به همین آزمایشگاه
                $alreadyInThisLab = DB::table('medical_centers_staffs')
                    ->where('center_id', $centerId)
                    ->where('staff_id', $user->id)
                    ->where('center_type', 'lab')
                    ->exists();

                if ($alreadyInThisLab) {
                    return response()->json([
                        'success' => false,
                        'message' => 'این کاربر در حال حاضر به عنوان کارمند در آزمایشگاه شما ثبت است.',
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                // بروزرسانی مشخصات و تغییر نقش به lab_staff
                DB::table('users')->where('id', $user->id)->update([
                    'name'       => $name,
                    'role'       => 'lab_staff',
                    'status'     => 1,
                    'is_verify'  => 1,
                    'updated_at' => $now,
                ]);

                // ساخت کیف پول در صورت عدم وجود
                $hasWallet = DB::table('wallets')->where('user_id', $user->id)->exists();
                if (!$hasWallet) {
                    DB::table('wallets')->insert([
                        'user_id'    => $user->id,
                        'balance'    => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                if (empty($user->novu_subscriber_id)) {
                    $isNewStaff = true;
                    $staffModel = (object) [
                        'id'    => $user->id,
                        'phone' => $phone,
                        'name'  => $name,
                    ];
                }

                $staffUserId = $user->id;
            } else {
                // ۲. ساخت کاربر جدید با نقش lab_staff
                $isNewStaff = true;

                $staffUserId = DB::table('users')->insertGetId([
                    'name'       => $name,
                    'phone'      => $phone,
                    'role'       => 'lab_staff',
                    'password'   => Hash::make($phone),
                    'status'     => 1,
                    'is_verify'  => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('wallets')->insert([
                    'user_id'    => $staffUserId,
                    'balance'    => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $staffModel = (object) [
                    'id'    => $staffUserId,
                    'phone' => $phone,
                    'name'  => $name,
                ];
            }

            // ۳. اتصال کارمند به مرکز آزمایشگاهی در medical_centers_staffs
            DB::table('medical_centers_staffs')->insert([
                'center_id'   => $centerId,
                'staff_id'    => $staffUserId,
                'center_type' => 'lab',
                'job_title'   => $jobTitle,
                'is_active'   => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            return [
                'id'        => (string) $staffUserId,
                'name'      => $name,
                'phone'     => $phone,
                'jobTitle'  => $jobTitle,
                'active'    => true,
                'createdAt' => $now->toIso8601String(),
            ];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        // ۴. ساخت سابسکرایبر در Novu (خارج از تراکنش)
        if ($isNewStaff && $staffModel) {
            $novuSubscriberId = (string) Str::uuid();
            try {
                $novuUrl = 'http://185.222.163.113:3000/v1/subscribers';
                $novuApiKey = config('services.novu.api_key');

                $response = Http::timeout(3)->withHeaders([
                    'Authorization' => 'ApiKey ' . $novuApiKey,
                    'Content-Type'  => 'application/json',
                ])->post($novuUrl, [
                    'subscriberId' => $novuSubscriberId,
                    'phone'        => $staffModel->phone,
                    'firstName'    => $staffModel->name,
                ]);

                if ($response->successful()) {
                    DB::table('users')->where('id', $staffModel->id)->update([
                        'novu_subscriber_id' => $novuSubscriberId,
                        'updated_at'         => now(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Novu API Lab Staff Exception: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'کارمند با موفقیت به آزمایشگاه افزوده شد.',
            'data'    => $result,
        ], Response::HTTP_CREATED);
    }

    /**
     * ویرایش اطلاعات کارمند
     */
    public function update(Request $request, int|string $id): JsonResponse
    {
        $centerId = $this->getLabCenterId($request);
        if (!$centerId) {
            return response()->json(['success' => false, 'message' => 'مرکز آزمایشگاه یافت نشد.'], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'name'      => 'required|string|min:3|max:100',
            'phone'     => 'required|string|regex:/^09[0-9]{9}$/',
            'job_title' => 'nullable|string|max:100',
        ]);

        $relation = DB::table('medical_centers_staffs')
            ->where('center_id', $centerId)
            ->where('staff_id', $id)
            ->where('center_type', 'lab')
            ->first();

        if (!$relation) {
            return response()->json(['success' => false, 'message' => 'کارمند یافت نشد.'], Response::HTTP_NOT_FOUND);
        }

        // بررسی تکراری نبودن شماره جدید با کاربر دیگر
        $phoneExists = DB::table('users')
            ->where('phone', $validated['phone'])
            ->where('id', '!=', $id)
            ->exists();

        if ($phoneExists) {
            return response()->json(['success' => false, 'message' => 'این شماره موبایل متعلق به حساب کاربری دیگری است.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::table('users')->where('id', $id)->update([
            'name'       => $validated['name'],
            'phone'      => $validated['phone'],
            'updated_at' => now(),
        ]);

        if (isset($validated['job_title'])) {
            DB::table('medical_centers_staffs')->where('id', $relation->id)->update([
                'job_title'  => $validated['job_title'],
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'اطلاعات کارمند با موفقیت ویرایش شد.',
        ]);
    }

    /**
     * حذف دسترسی کارمند از آزمایشگاه
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $centerId = $this->getLabCenterId($request);
        if (!$centerId) {
            return response()->json(['success' => false, 'message' => 'مرکز آزمایشگاه یافت نشد.'], Response::HTTP_NOT_FOUND);
        }

        $deleted = DB::table('medical_centers_staffs')
            ->where('center_id', $centerId)
            ->where('staff_id', $id)
            ->where('center_type', 'lab')
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'کارمند مورد نظر یافت نشد.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'message' => 'کارمند از لیست آزمایشگاه حذف شد.',
        ]);
    }
}
