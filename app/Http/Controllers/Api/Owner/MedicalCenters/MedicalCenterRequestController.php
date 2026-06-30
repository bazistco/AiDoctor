<?php
// app/Http/Controllers/Owner/MedicalCenters/MedicalCenterRequestController.php

namespace App\Http\Controllers\Api\Owner\MedicalCenters;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MedicalCenterRequestController extends Controller
{
    use ApiResponse;

    /**
     * لیست تمام درخواست‌های مرکز درمانی
     */
    public function index(Request $request)
    {
        $medicalCenterId = $request->medical_center_id;

        $query = DB::table('users_medical_center_requests as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('medical_center_services as mcs', 'mcs.id', '=', 'r.medical_center_service_id')
            ->leftJoin('medical_services as ms', 'ms.id', '=', 'mcs.medical_service_id')
            ->where('r.medical_center_id', $medicalCenterId)
            ->select(
                'r.id',
                'r.user_id',
                'r.medical_center_service_id',
                'r.price',
                'r.status',
                'r.created_at',
                'u.name as user_name',
                'u.mobile as user_mobile',
                'ms.name as service_name'
            );

        // فیلتر وضعیت
        if (!is_null($request->query('status'))) {
            $query->where('r.status', $request->query('status'));
        }

        // فیلتر تاریخ از
        if ($request->query('from_date')) {
            $query->where('r.created_at', '>=', $request->query('from_date'));
        }

        // فیلتر تاریخ تا
        if ($request->query('to_date')) {
            $query->where('r.created_at', '<=', $request->query('to_date'));
        }

        return $this->paginated(
            $query->orderByDesc('r.id')->paginate($request->query('per_page', 15))
        );
    }

    /**
     * جزئیات یک درخواست
     */
    public function show(Request $request, int $id)
    {
        $medicalCenterId = $request->medical_center_id;

        $req = DB::table('users_medical_center_requests as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('medical_center_services as mcs', 'mcs.id', '=', 'r.medical_center_service_id')
            ->leftJoin('medical_services as ms', 'ms.id', '=', 'mcs.medical_service_id')
            ->where('r.id', $id)
            ->where('r.medical_center_id', $medicalCenterId)
            ->select(
                'r.*',
                'u.name as user_name',
                'u.mobile as user_mobile',
                'u.national_code as user_national_code',
                'ms.name as service_name',
                'mcs.description as service_description',
                'mcs.date as service_date'
            )
            ->first();

        if (!$req) {
            return $this->error('درخواست یافت نشد', 404);
        }

        return $this->success(['request' => $req]);
    }

    /**
     * بروزرسانی وضعیت درخواست
     */
    public function updateStatus(Request $request, int $id)
    {
        $medicalCenterId = $request->medical_center_id;

        $exists = DB::table('users_medical_center_requests')
            ->where('id', $id)
            ->where('medical_center_id', $medicalCenterId)
            ->exists();

        if (!$exists) {
            return $this->error('درخواست یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('users_medical_center_requests')
            ->where('id', $id)
            ->where('medical_center_id', $medicalCenterId)
            ->update(['status' => $request->input('status')]);

        return $this->success(null, 'وضعیت درخواست بروزرسانی شد');
    }

    /**
     * آمار کلی درخواست‌ها
     */
    public function stats(Request $request)
    {
        $medicalCenterId = $request->medical_center_id;

        $total = DB::table('users_medical_center_requests')->where('medical_center_id', $medicalCenterId)->count();
        $pending = DB::table('users_medical_center_requests')->where('medical_center_id', $medicalCenterId)->where('status', 0)->count();
        $confirmed = DB::table('users_medical_center_requests')->where('medical_center_id', $medicalCenterId)->where('status', 1)->count();
        $rejected = DB::table('users_medical_center_requests')->where('medical_center_id', $medicalCenterId)->where('status', 2)->count();

        return $this->success([
            'total'     => $total,
            'pending'   => $pending,
            'confirmed' => $confirmed,
            'rejected'  => $rejected,
        ]);
    }
}
