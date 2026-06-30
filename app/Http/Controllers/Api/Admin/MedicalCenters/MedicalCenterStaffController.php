<?php
// app/Http/Controllers/Admin/MedicalCenters/MedicalCenterStaffController.php

namespace App\Http\Controllers\Api\Admin\MedicalCenters;

use App\Http\Controllers\Api\Admin\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MedicalCenterStaffController extends BaseController
{
    public function index(Request $request)
    {
        $query = DB::table('medical_center_staffs as s')
            ->join('medical_centers_info as mc', 'mc.id', '=', 's.medical_center_id')
            ->select('s.*', 'mc.name as center_name');

        if ($mcId = $request->query('medical_center_id')) {
            $query->where('s.medical_center_id', $mcId);
        }

        return $this->paginated(
            $query->orderByDesc('s.id')->paginate($request->query('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'medical_center_id' => 'required|integer|exists:medical_centers_info,id',
            'name'              => 'required|string|max:255',
            'mobile'            => 'required|string|max:20',
            'national_code'     => 'nullable|string|max:20',
            'status'            => 'required|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        $id = DB::table('medical_center_staffs')->insertGetId($validator->validated());

        return $this->success(['id' => $id], 'پرسنل ایجاد شد', 201);
    }

    public function update(Request $request, int $id)
    {
        if (!DB::table('medical_center_staffs')->where('id', $id)->exists()) {
            return $this->error('یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'medical_center_id' => 'sometimes|required|integer|exists:medical_centers_info,id',
            'name'              => 'sometimes|required|string|max:255',
            'mobile'            => 'sometimes|required|string|max:20',
            'national_code'     => 'nullable|string|max:20',
            'status'            => 'sometimes|required|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('medical_center_staffs')->where('id', $id)->update($validator->validated());

        return $this->success(null, 'بروزرسانی شد');
    }

    public function destroy(int $id)
    {
        if (!DB::table('medical_center_staffs')->where('id', $id)->exists()) {
            return $this->error('یافت نشد', 404);
        }

        DB::table('medical_center_staffs')->where('id', $id)->delete();

        return $this->success(null, 'حذف شد');
    }
}
