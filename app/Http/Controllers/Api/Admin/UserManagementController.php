<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class UserManagementController extends Controller
{
    private function baseQuery()
    {
        return DB::table('users as u')
            ->join('cities as c', 'c.id', '=', 'u.city_id')
            ->join('provinces as p', 'p.id', '=', 'u.province_id')
            ->leftJoin('doctor_info as di', 'di.user_id', '=', 'u.id')
            ->leftJoin('beauty_clinic_info as bci', 'bci.user_id', '=', 'u.id')
            ->select(
                'u.id',
                'u.name',
                'u.email',
                'u.phone',
                'u.role',
                'u.status',
                'u.is_verify',
                'u.avatar',
                'u.created_at',
                'u.updated_at',
                'c.id as city',
                'p.id as province',
                // فیلدهای مربوط به پزشک
                'di.document_status',
                'di.document_rejection_reason',
                'di.national_code',
                'di.is_national_code_verified',
                'di.medical_code',
                'di.is_avatar_verified as doctor_avatar_verified',
                'di.image_url as doctor_image_url',
                'di.national_card_image',
                'di.medical_council_card_image',
                'di.clinic_license_image',
                // فیلدهای مربوط به کلینیک زیبایی
                'bci.image as beauty_image',
                'bci.is_avatar_verified as beauty_avatar_verified',
                'bci.address as beauty_address',
                'bci.phone as beauty_phone'
            );
    }

    private function formatUser(object $user): array
    {
        $getDocUrl = fn($path) => $path ? secure_url('/api/admin/documents/download?path=' . urlencode($path)) : null;

        $beautyImageUrl = !empty($user->beauty_image)
            ? (str_starts_with($user->beauty_image, 'http')
                ? $user->beauty_image
                : 'https://api.mediraai.com/storage/' . ltrim($user->beauty_image, '/'))
            : null;

        $displayAvatar = $user->role === 'beauty_clinic'
            ? ($beautyImageUrl ?? $user->avatar)
            : ($user->doctor_image_url ?? $user->avatar);

        return [
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'phone'      => $user->phone,
            'role'       => $user->role,
            'type'       => $user->role,
            'status'     => $user->status,
            'is_verify'  => (bool) $user->is_verify,
            'isVerified' => (bool) $user->is_verify,
            'avatar'     => $displayAvatar,
            'city'       => $user->city,
            'province'   => $user->province,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,

            // اطلاعات احراز و مدارک پزشک
            'doctor_info' => $user->role === 'doctor' ? [
                'national_code'             => $user->national_code,
                'is_national_code_verified' => (bool) $user->is_national_code_verified,
                'medical_code'              => $user->medical_code,
                'document_status'           => $user->document_status ?? 'not_uploaded',
                'document_rejection_reason' => $user->document_rejection_reason,
                'is_avatar_verified'        => (bool) $user->doctor_avatar_verified,
                'documents' => [
                    'national_card'        => $getDocUrl($user->national_card_image),
                    'medical_council_card' => $getDocUrl($user->medical_council_card_image),
                    'clinic_license'       => $getDocUrl($user->clinic_license_image),
                ]
            ] : null,

            // اطلاعات تصویر و پروفایل کلینیک زیبایی
            'beauty_clinic_info' => $user->role === 'beauty_clinic' ? [
                'image_url'          => $beautyImageUrl,
                'is_avatar_verified' => (bool) $user->beauty_avatar_verified,
                'address'            => $user->beauty_address,
                'phone'              => $user->beauty_phone,
            ] : null,
        ];
    }

    public function pendingDoctors(Request $request)
    {
        $perPage      = (int) $request->input('per_page', 15);
        $statusFilter = $request->input('status_filter', 'action_required');
        $roleFilter   = $request->input('role_filter', 'all'); // all | doctor | beauty_clinic

        $query = $this->baseQuery()
            ->where(function ($root) use ($statusFilter, $roleFilter) {

                // ۱. بخش پزشکان
                if ($roleFilter === 'all' || $roleFilter === 'doctor') {
                    $root->orWhere(function ($docQ) use ($statusFilter) {
                        $docQ->where('u.role', 'doctor')
                            ->where(function ($q) use ($statusFilter) {
                                if ($statusFilter === 'all') {
                                    $q->whereIn('di.document_status', ['not_uploaded', 'pending', 'rejected'])
                                        ->orWhereNull('di.document_status')
                                        ->orWhere(function ($avatarSub) {
                                            $avatarSub->where(function ($inner) {
                                                $inner->where('di.is_avatar_verified', false)
                                                    ->orWhereNull('di.is_avatar_verified');
                                            })
                                                ->whereNotNull('di.image_url')
                                                ->where('di.image_url', '<>', '');
                                        });
                                } elseif ($statusFilter === 'not_uploaded') {
                                    $q->whereIn('di.document_status', ['not_uploaded'])
                                        ->orWhereNull('di.document_status');
                                } elseif ($statusFilter === 'pending') {
                                    $q->where('di.document_status', 'pending');
                                } elseif ($statusFilter === 'avatar_unverified') {
                                    $q->whereNotNull('di.image_url')
                                        ->where('di.image_url', '<>', '')
                                        ->where(function ($sub) {
                                            $sub->where('di.is_avatar_verified', false)
                                                ->orWhereNull('di.is_avatar_verified');
                                        });
                                } else {
                                    $q->where('di.document_status', 'pending')
                                        ->orWhere(function ($sub) {
                                            $sub->whereNotNull('di.image_url')
                                                ->where('di.image_url', '<>', '')
                                                ->where(function ($avatarQ) {
                                                    $avatarQ->where('di.is_avatar_verified', false)
                                                        ->orWhereNull('di.is_avatar_verified');
                                                });
                                        });
                                }
                            });
                    });
                }

                // ۲. بخش کلینیک‌های زیبایی
                if (
                    ($roleFilter === 'all' || $roleFilter === 'beauty_clinic') &&
                    !in_array($statusFilter, ['pending', 'not_uploaded'], true)
                ) {
                    $root->orWhere(function ($beautyQ) use ($statusFilter) {
                        $beautyQ->where('u.role', 'beauty_clinic');

                        if ($statusFilter === 'all') {
                            $beautyQ->where(function ($sub) {
                                $sub->where('bci.is_avatar_verified', false)
                                    ->orWhereNull('bci.is_avatar_verified');
                            });
                        } else {
                            // حالت action_required و avatar_unverified:
                            // کلینیک‌هایی که تصویر آپلود کرده‌اند ولی هنوز تایید نشده است
                            $beautyQ->whereNotNull('bci.image')
                                ->where('bci.image', '<>', '')
                                ->where(function ($sub) {
                                    $sub->where('bci.is_avatar_verified', false)
                                        ->orWhereNull('bci.is_avatar_verified');
                                });
                        }
                    });
                }
            });

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('u.name', 'like', "%{$search}%")
                    ->orWhere('u.phone', 'like', "%{$search}%")
                    ->orWhere('di.national_code', 'like', "%{$search}%");
            });
        }

        if ($phone = $request->input('phone')) {
            $query->where('u.phone', 'like', "%{$phone}%");
        }

        if ($province = $request->input('province')) {
            $query->where('p.name', $province);
        }

        if ($city = $request->input('city')) {
            $query->where('c.name', $city);
        }

        $query->orderByRaw("
            CASE
                WHEN u.role = 'doctor' AND di.document_status = 'pending' THEN 1
                WHEN (u.role = 'doctor' AND (di.is_avatar_verified = 0 OR di.is_avatar_verified IS NULL))
                  OR (u.role = 'beauty_clinic' AND (bci.is_avatar_verified = 0 OR bci.is_avatar_verified IS NULL)) THEN 2
                ELSE 3
            END ASC
        ")->orderBy('u.id', 'desc');

        $paginated = $query->paginate($perPage);

        $formattedData = collect($paginated->items())->map(function ($row) {
            return $this->formatUser($row);
        });

        return response()->json([
            'success' => true,
            'data'    => $formattedData,
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ]
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firstName' => 'required|string|max:255',
            'lastName'  => 'required|string|max:255',
            'phone'     => 'required|string|size:11|unique:users,phone|regex:/^09[0-9]{9}$/',
            'province'  => 'required|string|max:100',
            'city'      => 'required|string|max:100',
            'status'    => 'required|in:active,inactive,blocked',
            'type'      => 'required|in:patient,doctor,pharmacy,lab,nurse,medical_center,beauty_clinic',
            'details'   => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $province = DB::table('provinces')->where('name', $request->province)->first();
            $city = DB::table('cities')
                ->where('name', $request->city)
                ->where('province_id', $province->id ?? null)
                ->first();

            if (!$province || !$city) {
                return response()->json([
                    'success' => false,
                    'message' => 'استان یا شهر معتبر نیست.'
                ], 400);
            }

            $statusMap = [
                'active'   => 1,
                'inactive' => 0,
                'blocked'  => 2,
            ];

            $roleMap = [
                'patient'        => 'patient',
                'doctor'         => 'doctor',
                'pharmacy'       => 'pharmacy',
                'lab'            => 'lab',
                'nurse'          => 'medical_center',
                'medical_center' => 'medical_center',
                'beauty_clinic'  => 'beauty_clinic',
            ];

            $details = $request->details ?? [];
            $fullName = trim($request->firstName . ' ' . $request->lastName);

            // اگر برای کلینیک زیبایی نام مرکز در details وارد شده بود، به‌عنوان نام کاربر ثبت شود
            $userDisplayName = ($request->type === 'beauty_clinic' && !empty($details['clinicName']))
                ? trim($details['clinicName'])
                : $fullName;

            $userId = DB::table('users')->insertGetId([
                'name'        => $userDisplayName,
                'phone'       => $request->phone,
                'password'    => Hash::make($request->phone),
                'role'        => $roleMap[$request->type],
                'status'      => $statusMap[$request->status],
                'is_verify'   => 0,
                'province_id' => $province->id,
                'city_id'     => $city->id,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            // ساخت کیف پول برای همه کاربران
            DB::table('wallets')->insert([
                'user_id'    => $userId,
                'balance'    => 0,
                'version'    => 0,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            switch ($request->type) {
                case 'doctor':
                    $this->createDoctorInfo(
                        $userId,
                        $fullName,
                        $details,
                        $request->province,
                        $request->city
                    );
                    break;

                case 'lab':
                    $this->createLabInfo($userId, $fullName, $details);
                    break;

                case 'pharmacy':
                case 'nurse':
                case 'medical_center':
                    $this->createMedicalCenterInfo($userId, $fullName, $details);
                    break;

                case 'beauty_clinic':
                    $this->createBeautyClinicInfo($userId, $userDisplayName, $request->phone, $details);
                    break;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'کاربر با موفقیت ایجاد شد.',
                'data'    => [
                    'id'    => $userId,
                    'phone' => $request->phone,
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'خطا در ایجاد کاربر: ' . $e->getMessage()
            ], 500);
        }
    }

    private function createDoctorInfo($userId, $name, $details, $province, $city)
    {
        $specialtyId = $details['specialty_id'] ?? null;

        if (!$specialtyId) {
            throw new \Exception('specialty_id برای دکتر الزامی است.');
        }

        DB::table('doctor_info')->insert([
            'user_id'        => $userId,
            'name'           => $name,
            'bio'            => $details['bio'] ?? null,
            'specialty_id'   => $specialtyId,
            'visit_price'    => $details['visit_price'] ?? 7000000,
            'experience'     => $details['experience'] ?? null,
            'address'        => $details['address'] ?? null,
            'phone'          => $details['phone'] ?? null,
            'medical_code'   => $details['medical_code'] ?? null,
            'rank'           => $details['rank'] ?? null,
            'image_url'      => $details['image_url'] ?? 'https://www.tarhdokan.com/wp-content/uploads/2020/07/doctor-1-1.jpg',
            'lat'            => $details['lat'] ?? null,
            'lng'            => $details['lng'] ?? null,
            'province'       => $province,
            'city'           => $city,
            'is_vip'         => 0,
            'rating'         => 0.0,
            'visit_count'    => 0,
            'appointments'   => 0,
            'reviews'        => 0,
            'recommendation' => 0,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    private function createLabInfo($userId, $name, $details)
    {
        $slug = Str::slug($name . '-' . $userId);

        DB::table('labs_info')->insert([
            'user_id'           => $userId,
            'name'              => $name,
            'slug'              => $slug,
            'license_number'    => $details['license_number'] ?? null,
            'technical_manager' => $details['technical_manager'] ?? null,
            'work_hours'        => $details['work_hours'] ?? null,
            'min_order_amount'  => $details['min_order_amount'] ?? 0,
            'status'            => 1,
            'address'           => $details['address'] ?? null,
            'lat'               => $details['lat'] ?? null,
            'lng'               => $details['lng'] ?? null,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    private function createMedicalCenterInfo($userId, $name, $details)
    {
        $slug = Str::slug($name . '-' . $userId);

        DB::table('medical_centers_info')->insert([
            'user_id'              => $userId,
            'name'                 => $name,
            'slug'                 => $slug,
            'status'               => 1,
            'image'                => $details['image'] ?? null,
            'lat'                  => $details['lat'] ?? null,
            'lng'                  => $details['lng'] ?? null,
            'address'              => $details['address'] ?? null,
            'coverage_description' => $details['coverage_description'] ?? null,
            'coverage_radius'      => $details['coverage_radius'] ?? null,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    private function createBeautyClinicInfo($userId, $clinicName, $defaultPhone, $details)
    {
        $slugBase = Str::slug($clinicName) ?: 'beauty-clinic';
        $slug     = "{$slugBase}-{$userId}";

        DB::table('beauty_clinic_info')->insert([
            'user_id'            => $userId,
            'slug'               => $slug,
            'phone'              => $details['phone'] ?? $defaultPhone,
            'work_hours'         => $details['workHours'] ?? ($details['work_hours'] ?? null),
            'address'            => $details['address'] ?? null,
            'description'        => $details['description'] ?? null,
            'lat'                => $details['lat'] ?? null,
            'lng'                => $details['lng'] ?? null,
            'image'              => $details['image'] ?? null,
            'is_avatar_verified' => 0,
            'status'             => 1,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }
}
