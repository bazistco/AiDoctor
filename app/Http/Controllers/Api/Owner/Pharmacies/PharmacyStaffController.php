<?php

namespace App\Http\Controllers\Api\Owner\Pharmacies;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PharmacyStaffController extends Controller
{
    /**
     * واکشی شناسه مرکز داروخانه (center_id) متعلق به کاربر داروخانه
     */
    private function getPharmacyCenterId(Request $request): ?int
    {
        $user = $request->user();

        $pharmacy = DB::table('pharmacies_info')
            ->where('user_id', $user->id)
            ->first();

        if ($pharmacy) {
            return (int) $pharmacy->user_id;
        }

        // fallback در صورتی که جدول pharmacies جداگانه با فیلد user_id باشد
        $legacy = DB::table('pharmacies')->where('user_id', $user->id)->first();
        return $legacy ? (int) $legacy->id : (int) $user->id;
    }

    /**
     * لیست پرسنل داروخانه
     */
    public function index(Request $request): JsonResponse
    {
        $centerId = $this->getPharmacyCenterId($request);
        if (!$centerId) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات داروخانه برای این حساب کاربری یافت نشد.',
            ], Response::HTTP_NOT_FOUND);
        }

        $staffs = DB::table('medical_centers_staffs')
            ->join('users', 'medical_centers_staffs.staff_id', '=', 'users.id')
            ->where('medical_centers_staffs.center_id', $centerId)
            ->where('medical_centers_staffs.center_type', 'pharmacy')
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
                    'jobTitle'  => $staff->job_title ?? 'پرسنل داروخانه',
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
     * فعال / غیرفعال‌سازی وضعیت کارمند
     */
    public function toggleStatus(Request $request, int|string $id): JsonResponse
    {
        $centerId = $this->getPharmacyCenterId($request);
        if (!$centerId) {
            return response()->json([
                'success' => false,
                'message' => 'مرکز داروخانه یافت نشد.',
            ], Response::HTTP_NOT_FOUND);
        }

        $relation = DB::table('medical_centers_staffs')
            ->where('center_id', $centerId)
            ->where('staff_id', $id)
            ->where('center_type', 'pharmacy')
            ->first();

        if (!$relation) {
            return response()->json([
                'success' => false,
                'message' => 'کارمند در این داروخانه یافت نشد.',
            ], Response::HTTP_NOT_FOUND);
        }

        $newActive = ((int) $relation->is_active === 1) ? 0 : 1;

        DB::table('medical_centers_staffs')
            ->where('id', $relation->id)
            ->update([
                'is_active'  => $newActive,
                'updated_at' => now(),
            ]);

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
     * افزودن کارمند جدید داروخانه
     */
    public function store(Request $request): JsonResponse
    {
        $centerId = $this->getPharmacyCenterId($request);
        if (!$centerId) {
            return response()->json([
                'success' => false,
                'message' => 'شناسه داروخانه شما مشخص نیست.',
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
        $jobTitle = $validated['job_title'] ?? 'کارمند داروخانه / نسخه‌پیچ';

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

            $user = DB::table('users')->where('phone', $phone)->first();

            if ($user) {
                if (!in_array($user->role, ['user', 'pharmacy_staff'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'این شماره موبایل متعلق به نقشی غیر از کارمند داروخانه یا کاربر عادی است.',
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $alreadyAssigned = DB::table('medical_centers_staffs')
                    ->where('center_id', $centerId)
                    ->where('staff_id', $user->id)
                    ->where('center_type', 'pharmacy')
                    ->exists();

                if ($alreadyAssigned) {
                    return response()->json([
                        'success' => false,
                        'message' => 'این کاربر در حال حاضر به عنوان کارمند در داروخانه شما ثبت است.',
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                DB::table('users')->where('id', $user->id)->update([
                    'name'       => $name,
                    'role'       => 'pharmacy_staff',
                    'status'     => 1,
                    'is_verify'  => 1,
                    'updated_at' => $now,
                ]);

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
                $isNewStaff = true;

                $staffUserId = DB::table('users')->insertGetId([
                    'name'       => $name,
                    'phone'      => $phone,
                    'role'       => 'pharmacy_staff',
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

            DB::table('medical_centers_staffs')->insert([
                'center_id'   => $centerId,
                'staff_id'    => $staffUserId,
                'center_type' => 'pharmacy',
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
                Log::error('Novu API Pharmacy Staff Exception: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'کارمند با موفقیت به داروخانه افزوده شد.',
            'data'    => $result,
        ], Response::HTTP_CREATED);
    }

    /**
     * ویرایش اطلاعات کارمند داروخانه
     */
    public function update(Request $request, int|string $id): JsonResponse
    {
        $centerId = $this->getPharmacyCenterId($request);
        if (!$centerId) {
            return response()->json(['success' => false, 'message' => 'مرکز داروخانه یافت نشد.'], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'name'      => 'required|string|min:3|max:100',
            'phone'     => 'required|string|regex:/^09[0-9]{9}$/',
            'job_title' => 'nullable|string|max:100',
        ]);

        $relation = DB::table('medical_centers_staffs')
            ->where('center_id', $centerId)
            ->where('staff_id', $id)
            ->where('center_type', 'pharmacy')
            ->first();

        if (!$relation) {
            return response()->json(['success' => false, 'message' => 'کارمند در داروخانه یافت نشد.'], Response::HTTP_NOT_FOUND);
        }

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
            'message' => 'اطلاعات کارمند داروخانه با موفقیت ویرایش شد.',
        ]);
    }

    /**
     * حذف ارتباط کارمند با داروخانه
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $centerId = $this->getPharmacyCenterId($request);
        if (!$centerId) {
            return response()->json(['success' => false, 'message' => 'مرکز داروخانه یافت نشد.'], Response::HTTP_NOT_FOUND);
        }

        $deleted = DB::table('medical_centers_staffs')
            ->where('center_id', $centerId)
            ->where('staff_id', $id)
            ->where('center_type', 'pharmacy')
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'کارمند مورد نظر در داروخانه یافت نشد.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'message' => 'کارمند از لیست پرسنل داروخانه حذف شد.',
        ]);
    }
}
