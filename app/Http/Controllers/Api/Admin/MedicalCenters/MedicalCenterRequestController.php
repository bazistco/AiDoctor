<?php
// app/Http/Controllers/Admin/MedicalCenters/MedicalCenterRequestController.php

namespace App\Http\Controllers\Api\Admin\MedicalCenters;

use App\Http\Controllers\Api\Admin\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MedicalCenterRequestController extends BaseController
{
    public function index(Request $request)
    {
        $query = DB::table('user_medical_center_requests as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->join('medical_centers_info as mc', 'mc.id', '=', 'r.medical_center_id')
            ->leftJoin('medical_center_staffs as s', 's.id', '=', 'r.staff_id')
            ->leftJoin('medical_services_time_types as tt', 'tt.id', '=', 'r.time_type_id')
            ->select(
                'r.id', 'r.user_id', 'r.medical_center_id', 'r.total_price',
                'r.start_time', 'r.end_time', 'r.time_type_id', 'r.staff_id', 'r.status',
                'u.name as user_name', 'u.mobile as user_mobile',
                'mc.name as center_name', 's.name as staff_name', 'tt.name as time_type_name'
            );

        if ($mcId = $request->query('medical_center_id')) {
            $query->where('r.medical_center_id', $mcId);
        }
        if (!is_null($request->query('status'))) {
            $query->where('r.status', $request->query('status'));
        }

        return $this->paginated(
            $query->orderByDesc('r.id')->paginate($request->query('per_page', 15))
        );
    }

    public function show(int $id)
    {
        $req = DB::table('user_medical_center_requests as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->join('medical_centers_info as mc', 'mc.id', '=', 'r.medical_center_id')
            ->where('r.id', $id)
            ->select('r.*', 'u.name as user_name', 'u.mobile as user_mobile', 'mc.name as center_name')
            ->first();

        if (!$req) {
            return $this->error('درخواست یافت نشد', 404);
        }

        $services = DB::table('user_medical_center_request_services as rs')
            ->join('medical_center_services as mcs', 'mcs.id', '=', 'rs.medical_center_service_id')
            ->join('medical_services as ms', 'ms.id', '=', 'mcs.medical_service_id')
            ->where('rs.umcr_request_id', $id)
            ->select('rs.id', 'rs.medical_center_service_id', 'rs.price', 'rs.date', 'ms.name as service_name')
            ->get();

        $files = DB::table('umcr_files')->where('umcr_id', $id)->get();

        return $this->success(['request' => $req, 'services' => $services, 'files' => $files]);
    }

    public function updateStatus(Request $request, int $id)
    {
        if (!DB::table('user_medical_center_requests')->where('id', $id)->exists()) {
            return $this->error('درخواست یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'status'   => 'required|integer',
            'staff_id' => 'nullable|integer|exists:medical_center_staffs,id',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('user_medical_center_requests')->where('id', $id)
            ->update($validator->validated());

        return $this->success(null, 'وضعیت درخواست بروزرسانی شد');
    }
}
