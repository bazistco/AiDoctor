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
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'phone' => 'required|string|size:11|unique:users,phone|regex:/^09[0-9]{9}$/',
            'province' => 'required|string|max:100',
            'city' => 'required|string|max:100',
            'status' => 'required|in:active,inactive,blocked',
            'type' => 'required|in:patient,doctor,pharmacy,lab,nurse',
            'details' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            // پیدا کردن province_id و city_id
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

            // تبدیل status
            $statusMap = [
                'active' => 1,
                'inactive' => 0,
                'blocked' => 2,
            ];

            // تبدیل type به role
            $roleMap = [
                'patient' => 'patient',
                'doctor' => 'doctor',
                'pharmacy' => 'pharmacy',
                'lab' => 'lab',
                'nurse' => 'patient', // یا نقش دیگه‌ای که برای nurse داری
            ];

            // ساخت رکورد user
            $userId = DB::table('users')->insertGetId([
                'name' => trim($request->firstName . ' ' . $request->lastName),
                'phone' => $request->phone,
                'password' => Hash::make($request->phone), // پسورد = شماره موبایل
                'role' => $roleMap[$request->type],
                'status' => $statusMap[$request->status],
                'is_verify' => 0,
                'province_id' => $province->id,
                'city_id' => $city->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // ذخیره جداول اطلاعات تکمیلی
            $details = $request->details ?? [];

            switch ($request->type) {
                case 'doctor':
                    $this->createDoctorInfo($userId, $request->firstName . ' ' . $request->lastName, $details, $request->province, $request->city);
                    break;

                case 'lab':
                    $this->createLabInfo($userId, $request->firstName . ' ' . $request->lastName, $details);
                    break;

                case 'pharmacy':
                    // اگر جدول pharmacy_info داری، اضافه کن
                    break;

                case 'nurse':
                    // اگه جدول nurse_info داری
                    break;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'کاربر با موفقیت ایجاد شد.',
                'data' => [
                    'id' => $userId,
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
            'user_id' => $userId,
            'name' => $name,
            'bio' => $details['bio'] ?? null,
            'specialty_id' => $specialtyId,
            'visit_price' => $details['visit_price'] ?? 500000,
            'experience' => $details['experience'] ?? null,
            'address' => $details['address'] ?? null,
            'phone' => $details['phone'] ?? null,
            'medical_code' => $details['medical_code'] ?? null,
            'rank' => $details['rank'] ?? null,
            'image_url' => $details['image_url'] ?? 'https://www.tarhdokan.com/wp-content/uploads/2020/07/doctor-1-1.jpg',
            'lat' => $details['lat'] ?? null,
            'lng' => $details['lng'] ?? null,
            'province' => $province,
            'city' => $city,
            'is_vip' => 0,
            'rating' => 0.0,
            'visit_count' => 0,
            'appointments' => 0,
            'reviews' => 0,
            'recommendation' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLabInfo($userId, $name, $details)
    {
        $slug = Str::slug($name . '-' . $userId);

        DB::table('lab_info')->insert([
            'user_id' => $userId,
            'name' => $name,
            'slug' => $slug,
            'license_number' => $details['license_number'] ?? null,
            'technical_manager' => $details['technical_manager'] ?? null,
            'work_hours' => $details['work_hours'] ?? null,
            'min_order_amount' => $details['min_order_amount'] ?? 0,
            'status' => 0,
            'address' => $details['address'] ?? null,
            'lat' => $details['lat'] ?? null,
            'lng' => $details['lng'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
