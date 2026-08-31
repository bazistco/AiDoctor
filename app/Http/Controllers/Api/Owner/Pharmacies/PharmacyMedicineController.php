<?php
// app/Http/Controllers/Owner/Pharmacies/PharmacyMedicineController.php

namespace App\Http\Controllers\Api\Owner\Pharmacies;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PharmacyMedicineController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = DB::table('pharmacy_medicines as pm')
            ->join('medicines as m', 'm.id', '=', 'pm.medicine_id')
            ->leftJoin('medicine_types as mt', 'mt.id', '=', 'pm.medicine_type_id')
            ->where('pm.pharmacy_id', $request->pharmacy_id)
            ->select(
                'pm.id', 'pm.medicine_id', 'pm.medicine_type_id', 'pm.unit',
                'pm.price_per_unit', 'pm.quantity', 'pm.status',
                'm.name as medicine_name', 'mt.name as type_name'
            );

        if (!is_null($request->query('status'))) {
            $query->where('pm.status', $request->query('status'));
        }

        return $this->paginated(
            $query->orderByDesc('pm.id')->paginate($request->query('per_page', 15))
        );
    }
    public function show(Request $request, int $id)
    {
        $pharmacyMedicine = DB::table('pharmacy_medicines as pm')
            ->join('medicines as m', 'm.id', '=', 'pm.medicine_id')
            ->leftJoin('medicine_types as mt', 'mt.id', '=', 'pm.medicine_type_id')
            ->where('pm.pharmacy_id', $request->pharmacy_id)
            ->where('pm.id', $id)
            ->select(
                'pm.id', 'pm.medicine_id', 'pm.medicine_type_id', 'pm.unit',
                'pm.price_per_unit', 'pm.quantity', 'pm.status',
                'm.name as medicine_name', 'mt.name as type_name'
            )
            ->first();

        if (!$pharmacyMedicine) {
            return $this->error('دارو یافت نشد', 404);
        }

        return $this->success($pharmacyMedicine);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
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

        $data = $validator->validated();
        $data['pharmacy_id'] = $request->pharmacy_id;

        $id = DB::table('pharmacy_medicines')->insertGetId($data);

        return $this->success(['id' => $id], 'دارو اضافه شد', 201);
    }

    public function update(Request $request, int $id)
    {
        $exists = DB::table('pharmacy_medicines')->where('id', $id)->where('pharmacy_id', $request->pharmacy_id)->exists();
        if (!$exists) {
            return $this->error('دارو یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
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

        DB::table('pharmacy_medicines')->where('id', $id)->where('pharmacy_id', $request->pharmacy_id)
            ->update($validator->validated());

        return $this->success(null, 'بروزرسانی شد');
    }

    public function destroy(Request $request, int $id)
    {
        $deleted = DB::table('pharmacy_medicines')->where('id', $id)->where('pharmacy_id', $request->pharmacy_id)->delete();
        if (!$deleted) {
            return $this->error('دارو یافت نشد', 404);
        }

        return $this->success(null, 'حذف شد');
    }
}
