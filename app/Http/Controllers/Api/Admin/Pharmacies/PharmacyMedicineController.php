<?php
// app/Http/Controllers/Admin/Pharmacies/PharmacyMedicineController.php

namespace App\Http\Controllers\Api\Admin\Pharmacies;

use App\Http\Controllers\Api\Admin\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PharmacyMedicineController extends BaseController
{
    public function index(Request $request)
    {
        $query = DB::table('pharmacy_medicines as pm')
            ->join('pharmacies_info as p', 'p.id', '=', 'pm.pharmacy_id')
            ->join('medicines as m', 'm.id', '=', 'pm.medicine_id')
            ->leftJoin('medicine_types as mt', 'mt.id', '=', 'pm.medicine_type_id')
            ->select(
                'pm.id', 'pm.pharmacy_id', 'pm.medicine_id', 'pm.medicine_type_id',
                'pm.unit', 'pm.price_per_unit', 'pm.quantity', 'pm.status',
                'p.name as pharmacy_name', 'm.name as medicine_name', 'mt.name as type_name'
            );

        if ($pharmacyId = $request->query('pharmacy_id')) {
            $query->where('pm.pharmacy_id', $pharmacyId);
        }
        if (!is_null($request->query('status'))) {
            $query->where('pm.status', $request->query('status'));
        }

        return $this->paginated(
            $query->orderByDesc('pm.id')->paginate($request->query('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pharmacy_id'      => 'required|integer|exists:pharmacies_info,id',
            'medicine_id'      => 'required|integer|exists:medicines,id',
            'medicine_type_id' => 'nullable|integer|exists:medicine_types,id',
            'unit'             => 'required|string|max:50',
            'price_per_unit'   => 'required|numeric|min:0',
            'quantity'         => 'required|integer|min:0',
            'status'           => 'required|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        $id = DB::table('pharmacy_medicines')->insertGetId($validator->validated());

        return $this->success(['id' => $id], 'داروی داروخانه ایجاد شد', 201);
    }

    public function update(Request $request, int $id)
    {
        if (!DB::table('pharmacy_medicines')->where('id', $id)->exists()) {
            return $this->error('یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'pharmacy_id'      => 'sometimes|required|integer|exists:pharmacies_info,id',
            'medicine_id'      => 'sometimes|required|integer|exists:medicines,id',
            'medicine_type_id' => 'nullable|integer|exists:medicine_types,id',
            'unit'             => 'sometimes|required|string|max:50',
            'price_per_unit'   => 'sometimes|required|numeric|min:0',
            'quantity'         => 'sometimes|required|integer|min:0',
            'status'           => 'sometimes|required|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('pharmacy_medicines')->where('id', $id)->update($validator->validated());

        return $this->success(null, 'بروزرسانی شد');
    }

    public function destroy(int $id)
    {
        if (!DB::table('pharmacy_medicines')->where('id', $id)->exists()) {
            return $this->error('یافت نشد', 404);
        }

        DB::table('pharmacy_medicines')->where('id', $id)->delete();

        return $this->success(null, 'حذف شد');
    }
}
