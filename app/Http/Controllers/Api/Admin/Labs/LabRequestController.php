<?php
// app/Http/Controllers/Admin/Labs/LabRequestController.php

namespace App\Http\Controllers\Api\Admin\Labs;

use App\Http\Controllers\Api\Admin\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LabRequestController extends BaseController
{
    public function index(Request $request)
    {
        $query = DB::table('users_labs_requests as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->join('labs_info as l', 'l.id', '=', 'r.lab_id')
            ->leftJoin('lab_request_types as rt', 'rt.id', '=', 'r.request_type_id')
            ->select(
                'r.id', 'r.user_id', 'r.lab_id', 'r.request_type_id',
                'r.user_prescription_id', 'r.total_price', 'r.status',
                'u.name as user_name', 'u.mobile as user_mobile',
                'l.name as lab_name', 'rt.name as request_type_name'
            );

        if ($labId = $request->query('lab_id')) {
            $query->where('r.lab_id', $labId);
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
        $req = DB::table('users_labs_requests as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->join('labs_info as l', 'l.id', '=', 'r.lab_id')
            ->where('r.id', $id)
            ->select('r.*', 'u.name as user_name', 'u.mobile as user_mobile', 'l.name as lab_name')
            ->first();

        if (!$req) {
            return $this->error('درخواست یافت نشد', 404);
        }

        $items = DB::table('lab_request_test_packs as rtp')
            ->join('labs_tests as lt', 'lt.id', '=', 'rtp.lab_test_id')
            ->join('test_packs as tp', 'tp.id', '=', 'lt.test_pack_id')
            ->where('rtp.lab_request_id', $id)
            ->select('rtp.id', 'rtp.lab_test_id', 'lt.price', 'tp.name as test_pack_name')
            ->get();

        return $this->success(['request' => $req, 'items' => $items]);
    }

    public function updateStatus(Request $request, int $id)
    {
        if (!DB::table('users_labs_requests')->where('id', $id)->exists()) {
            return $this->error('درخواست یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('users_labs_requests')->where('id', $id)
            ->update(['status' => $request->input('status')]);

        return $this->success(null, 'وضعیت درخواست بروزرسانی شد');
    }
}
