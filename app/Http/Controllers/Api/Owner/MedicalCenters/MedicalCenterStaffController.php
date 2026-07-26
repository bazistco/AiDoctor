<?php
namespace App\Http\Controllers\Api\Owner\MedicalCenters;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MedicalCenterStaffController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = DB::table('medical_center_staffs')
            ->where('medical_center_id', auth()->user()->id)
            ->orderByDesc('id');

        return $this->paginated($query->paginate($request->query('per_page',15)));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'          => 'required|string|max:255',
            'mobile'        => 'required|string|max:20',
            'national_code' => 'nullable|string|max:20',
            'gender'        => 'required|string|in:male,female',
            'status'        => 'required|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        $data = $validator->validated();
        $data['medical_center_id'] = auth()->user()->id;
        $data['created_at'] = now();
        $data['updated_at'] = now();

        // بررسی یکتا بودن کد ملی در مرکز درمانی
        if (!empty($data['national_code'])) {
            $exists = DB::table('medical_center_staffs')
                ->where('medical_center_id', auth()->user()->id)
                ->where('national_code', $data['national_code'])
                ->exists();

            if ($exists) {
                return $this->error('کد ملی قبلاً ثبت شده است', 409);
            }
        }

        try {
            $id = DB::table('medical_center_staffs')->insertGetId($data);

            $staff = DB::table('medical_center_staffs')
                ->where('id', $id)
                ->where('medical_center_id', auth()->user()->id)
                ->first();

            return $this->success($staff, 'پرسنل اضافه شد', 201);
        } catch (\Exception $e) {
            // گرفتن خطای constraint از دیتابیس
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                return $this->error('کد ملی تکراری است', 409);
            }
            return $this->error('خطا در ذخیره سازی', 500);
        }
    }

    public function show(Request $request, int $id)
    {
        $staff = DB::table('medical_center_staffs')
            ->where('id', $id)
            ->where('medical_center_id', auth()->user()->id)
            ->first();

        if (!$staff) {
            return $this->error('پرسنل یافت نشد', 404);
        }

        return $this->success($staff);
    }

    public function update(Request $request, int $id)
    {
        // ابتدا چک کنیم که پرسنل وجود دارد
        $staff = DB::table('medical_center_staffs')
            ->where('id', $id)
            ->where('medical_center_id', auth()->user()->id)
            ->first();

        if (!$staff) {
            return $this->error('پرسنل یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'name'          => 'sometimes|required|string|max:255',
            'mobile'        => 'sometimes|required|string|max:20',
            'national_code' => 'nullable|string|max:20',
            'gender'        => 'sometimes|required|string|in:male,female',
            'status'        => 'sometimes|required|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        $data = $validator->validated();

        // بررسی یکتا بودن کد ملی برای ویرایش
        if (isset($data['national_code']) && $data['national_code'] !== $staff->national_code) {
            $exists = DB::table('medical_center_staffs')
                ->where('medical_center_id', auth()->user()->id)
                ->where('national_code', $data['national_code'])
                ->where('id', '!=', $id) // به جز رکورد فعلی
                ->exists();

            if ($exists) {
                return $this->error('کد ملی قبلاً ثبت شده است', 409);
            }
        }

        try {
            DB::table('medical_center_staffs')
                ->where('id', $id)
                ->where('medical_center_id', auth()->user()->id)
                ->update(array_merge($data, ['updated_at' => now()]));

            $updatedStaff = DB::table('medical_center_staffs')
                ->where('id', $id)
                ->where('medical_center_id', auth()->user()->id)
                ->first();

            return $this->success($updatedStaff, 'بروزرسانی شد');
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                return $this->error('کد ملی تکراری است', 409);
            }
            return $this->error('خطا در بروزرسانی', 500);
        }
    }

    public function destroy(Request $request, int $id)
    {
        $deleted = DB::table('medical_center_staffs')
            ->where('id', $id)
            ->where('medical_center_id', auth()->user()->id)
            ->delete();

        if (!$deleted) {
            return $this->error('پرسنل یافت نشد', 404);
        }

        return $this->success(null, 'حذف شد');
    }
}
