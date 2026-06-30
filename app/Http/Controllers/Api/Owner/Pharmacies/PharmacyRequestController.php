<?php
// app/Http/Controllers/Owner/Pharmacies/PharmacyRequestController.php

namespace App\Http\Controllers\Api\Owner\Pharmacies;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PharmacyRequestController extends Controller
{
    use ApiResponse;

    /**
     * لیست تمام درخواست‌های داروخانه
     */
    public function index(Request $request)
    {
        $pharmacyId = $request->pharmacy_id;

        $query = DB::table('users_pharmacy_requests as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.pharmacy_id', $pharmacyId)
            ->select(
                'r.id',
                'r.user_id',
                'r.prescription_id',
                'r.total_price',
                'r.status',
                'r.created_at',
                'u.name as user_name',
                'u.mobile as user_mobile'
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
     * جزئیات یک درخواست + لیست داروهای آن
     */
    public function show(Request $request, int $id)
    {
        $pharmacyId = $request->pharmacy_id;

        // اطلاعات اصلی درخواست
        $req = DB::table('users_pharmacy_requests as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.id', $id)
            ->where('r.pharmacy_id', $pharmacyId)
            ->select(
                'r.*',
                'u.name as user_name',
                'u.mobile as user_mobile',
                'u.national_code as user_national_code'
            )
            ->first();

        if (!$req) {
            return $this->error('درخواست یافت نشد', 404);
        }

        // آیتم‌های درخواست (داروها)
        $items = DB::table('user_pharmacy_request_medicines as rm')
            ->join('pharmacy_medicines as pm', 'pm.id', '=', 'rm.pharmacy_medicine_id')
            ->join('medicines as m', 'm.id', '=', 'pm.medicine_id')
            ->leftJoin('medicine_types as mt', 'mt.id', '=', 'pm.medicine_type_id')
            ->where('rm.user_pharmacy_request_id', $id)
            ->select(
                'rm.id',
                'rm.pharmacy_medicine_id',
                'rm.quantity',
                'rm.price',
                'rm.status',
                'm.name as medicine_name',
                'mt.name as medicine_type_name',
                'pm.unit'
            )
            ->get();

        return $this->success([
            'request' => $req,
            'items'   => $items,
        ]);
    }

    /**
     * بروزرسانی وضعیت درخواست
     */
    public function updateStatus(Request $request, int $id)
    {
        $pharmacyId = $request->pharmacy_id;

        $exists = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
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

        DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->update(['status' => $request->input('status')]);

        return $this->success(null, 'وضعیت درخواست بروزرسانی شد');
    }

    /**
     * بروزرسانی وضعیت یک داروی خاص در درخواست
     */
    public function updateItemStatus(Request $request, int $requestId, int $itemId)
    {
        $pharmacyId = $request->pharmacy_id;

        // بررسی اینکه درخواست متعلق به این داروخانه هست
        $requestExists = DB::table('users_pharmacy_requests')
            ->where('id', $requestId)
            ->where('pharmacy_id', $pharmacyId)
            ->exists();

        if (!$requestExists) {
            return $this->error('درخواست یافت نشد', 404);
        }

        // بررسی اینکه آیتم متعلق به این درخواست هست
        $itemExists = DB::table('user_pharmacy_request_medicines')
            ->where('id', $itemId)
            ->where('user_pharmacy_request_id', $requestId)
            ->exists();

        if (!$itemExists) {
            return $this->error('آیتم یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('user_pharmacy_request_medicines')
            ->where('id', $itemId)
            ->update(['status' => $request->input('status')]);

        return $this->success(null, 'وضعیت دارو بروزرسانی شد');
    }

    /**
     * آمار کلی درخواست‌ها
     */
    public function stats(Request $request)
    {
        $pharmacyId = $request->pharmacy_id;

        $total = DB::table('users_pharmacy_requests')->where('pharmacy_id', $pharmacyId)->count();
        $pending = DB::table('users_pharmacy_requests')->where('pharmacy_id', $pharmacyId)->where('status', 0)->count();
        $confirmed = DB::table('users_pharmacy_requests')->where('pharmacy_id', $pharmacyId)->where('status', 1)->count();
        $rejected = DB::table('users_pharmacy_requests')->where('pharmacy_id', $pharmacyId)->where('status', 2)->count();

        return $this->success([
            'total'     => $total,
            'pending'   => $pending,
            'confirmed' => $confirmed,
            'rejected'  => $rejected,
        ]);
    }
}
