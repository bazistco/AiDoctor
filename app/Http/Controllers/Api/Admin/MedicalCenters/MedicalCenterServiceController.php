<?php
// app/Http/Controllers/Admin/MedicalCenters/MedicalCenterServiceController.php

namespace App\Http\Controllers\Api\Admin\MedicalCenters;

use App\Http\Controllers\Api\Admin\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MedicalCenterServiceController extends BaseController
{
    public function index(Request $request)
    {
        $query = DB::table('medical_center_services as mcs')
            ->join('medical_centers_info as mc', 'mc.id', '=', 'mcs.medical_center_id')
            ->join('medical_services as ms', 'ms.id', '=', 'mcs.medical_service_id')
            ->select(
                'mcs.id', 'mcs.medical_center_id', 'mcs.medical_service_id',
                'mcs.description', 'mcs.price', 'mcs.status', 'mcs.date',
                'mc.name as center_name', 'ms.name as service_name'
            );

        if ($mcId = $request->query('medical_center_id')) {
            $query->where('mcs.medical_center_id', $mcId);
        }
        if (!is_null($request->query('status'))) {
            $query->where('mcs.status', $request->query('status'));
        }

        return $this->paginated(
            $query->orderByDesc('mcs.id')->paginate($request->query('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'medical_center_id'  => 'required|integer|exists:medical_centers_info,id',
            'medical_service_id' => 'required|integer|exists:medical_services,id',
            'description'        => 'nullable|string',
            'price'              => 'required|numeric|min:0',
            'status'             => 'required|integer|in:0,1',
            'date'               => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        $id = DB::table('medical_center_services')->insertGetId($validator->validated());

        return $this->success(['id' => $id], 'خدمت ایجاد شد', 201);
    }

    public function update(Request $request, int $id)
    {
        if (!DB::table('medical_center_services')->where('id', $id)->exists()) {
            return $this->error('یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'medical_center_id'  => 'sometimes|required|integer|exists:medical_centers_info,id',
            'medical_service_id' => 'sometimes|required|integer|exists:medical_services,id',
            'description'        => 'nullable|string',
            'price'              => 'sometimes|required|numeric|min:0',
            'status'             => 'sometimes|required|integer|in:0,1',
            'date'               => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('medical_center_services')->where('id', $id)->update($validator->validated());

        return $this->success(null, 'بروزرسانی شد');
    }

    public function destroy(int $id)
    {
        if (!DB::table('medical_center_services')->where('id', $id)->exists()) {
            return $this->error('یافت نشد', 404);
        }

        DB::table('medical_center_services')->where('id', $id)->delete();

        return $this->success(null, 'حذف شد');
    }
}
