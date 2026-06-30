<?php
// app/Http/Controllers/Owner/MedicalCenters/MedicalCenterStaffController.php

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
        return $this->paginated(
            DB::table('medical_center_staffs')->where('medical_center_id', $request->medical_center_id)
                ->orderByDesc('id')->paginate($request->query('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'          => 'required|string|max:255',
            'mobile'        => 'required|string|max:20',
            'national_code' => 'nullable|string|max:20',
            'status'        => 'required|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        $data = $validator->validated();
        $data['medical_center_id'] = $request->medical_center_id;

        $id = DB::table('medical_center_staffs')->insertGetId($data);

        return $this->success(['id' => $id], 'پرسنل اضافه شد', 201);
    }

    public function update(Request $request, int $id)
    {
        $exists = DB::table('medical_center_staffs')->where('id', $id)->where('medical_center_id', $request->medical_center_id)->exists();
        if (!$exists) {
            return $this->error('پرسنل یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'name'          => 'sometimes|required|string|max:255',
            'mobile'        => 'sometimes|required|string|max:20',
            'national_code' => 'nullable|string|max:20',
            'status'        => 'sometimes|required|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('medical_center_staffs')->where('id', $id)->where('medical_center_id', $request->medical_center_id)
            ->update($validator->validated());

        return $this->success(null, 'بروزرسانی شد');
    }

    public function destroy(Request $request, int $id)
    {
        $deleted = DB::table('medical_center_staffs')->where('id', $id)->where('medical_center_id', $request->medical_center_id)->delete();
        if (!$deleted) {
            return $this->error('پرسنل یافت نشد', 404);
        }

        return $this->success(null, 'حذف شد');
    }
}
