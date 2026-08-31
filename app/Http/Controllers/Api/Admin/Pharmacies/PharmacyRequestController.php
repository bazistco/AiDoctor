<?php
// app/Http/Controllers/Admin/Pharmacies/PharmacyRequestController.php

namespace App\Http\Controllers\Api\Admin\Pharmacies;

use App\Http\Controllers\Api\Admin\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PharmacyRequestController extends BaseController
{
    public function index(Request $request)
    {
        $query = DB::table('users_pharmacy_requests as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->join('pharmacies_info as p', 'p.id', '=', 'r.pharmacy_id')
            ->select(
                'r.id', 'r.user_id', 'r.pharmacy_id', 'r.prescription_id',
                'r.total_price', 'r.status', 'r.created_at',
                'u.name as user_name', 'u.mobile as user_mobile', 'p.name as pharmacy_name'
            );

        if ($pharmacyId = $request->query('pharmacy_id')) {
            $query->where('r.pharmacy_id', $pharmacyId);
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
        $req = DB::table('users_pharmacy_requests as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->join('pharmacies_info as p', 'p.id', '=', 'r.pharmacy_id')
            ->where('r.id', $id)
            ->select('r.*', 'u.name as user_name', 'u.mobile as user_mobile', 'p.name as pharmacy_name')
            ->first();

        if (!$req) {
            return $this->error('درخواست یافت نشد', 404);
        }

        $items = DB::table('user_pharmacy_request_medicines as rm')
            ->join('pharmacy_medicines as pm', 'pm.id', '=', 'rm.pharmacy_medicine_id')
            ->join('medicines as m', 'm.id', '=', 'pm.medicine_id')
            ->where('rm.user_pharmacy_request_id', $id)
            ->select('rm.id', 'rm.pharmacy_medicine_id', 'rm.quantity', 'rm.price', 'rm.status', 'm.name as medicine_name')
            ->get();

        return $this->success(['request' => $req, 'items' => $items]);
    }

    public function updateStatus(Request $request, int $id)
    {
        if (!DB::table('users_pharmacy_requests')->where('id', $id)->exists()) {
            return $this->error('درخواست یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('users_pharmacy_requests')->where('id', $id)
            ->update(['status' => $request->input('status')]);

        return $this->success(null, 'وضعیت درخواست بروزرسانی شد');
    }
}
