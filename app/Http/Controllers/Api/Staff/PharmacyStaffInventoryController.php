<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PharmacyStaffInventoryController extends Controller
{
    /**
     * دریافت pharmacy_id پرسنل جاری
     */
    protected function getPharmacyId()
    {
        $userId = auth()->id();

        $staff = DB::table('medical_centers_staffs')
            ->where('staff_id', $userId)
            ->where('center_type', 'pharmacy')
            ->where('is_active', 1)
            ->first();

        return $staff ? $staff->center_id : null;
    }

    /**
     * لیست موجودی داروهای داروخانه با امکان جستجو و فیلتر اقلام رو به اتمام
     */
    public function index(Request $request)
    {
        $pharmacyId = $this->getPharmacyId();
        if (!$pharmacyId) {
            return response()->json([
                'status'  => 'error',
                'message' => 'داروخانه متناظر با این حساب کاربری یافت نشد.',
            ], 403);
        }

        $query = DB::table('pharmacy_medicines as pm')
            ->join('medicines as m', 'pm.medicine_id', '=', 'm.id')
            ->join('medicine_types as mt', 'mt.id', '=', 'pm.medicine_type_id')
            ->where('pm.pharmacy_id', $pharmacyId)
            ->select([
                'pm.id',
                'pm.medicine_id',
                'm.name as name',
                'mt.name as form', // یا شکل دارویی مثلا قرص، شربت و ...
                'pm.stock',
                'pm.min_stock as minStock',
                'pm.updated_at',
            ]);

        // فیلتر جستجوی نام دارو
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where('m.name', 'like', "%{$search}%");
        }

        // فیلتر داروهای رو به اتمام (stock < min_stock)
        if ($request->boolean('low_only')) {
            $query->whereRaw('pm.stock < pm.min_stock');
        }

        $items = $query->orderBy('pm.stock', 'asc')->get();

        // شمارش تعداد داروهای کم‌موجودی جهت نمایش Badge در هدر
        $lowStockCount = DB::table('pharmacy_medicines')
            ->where('pharmacy_id', $pharmacyId)
            ->whereRaw('stock < min_stock')
            ->count();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'items'          => $items,
                'low_stock_count'=> $lowStockCount,
            ],
        ]);
    }

    /**
     * به‌روزرسانی تعداد موجودی توسط کارمند
     */
    public function updateStock(Request $request, $id)
    {
        $pharmacyId = $this->getPharmacyId();
        if (!$pharmacyId) {
            return response()->json([
                'status'  => 'error',
                'message' => 'دسترسی غیرمجاز.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'stock' => 'required|integer|min:0',
        ], [
            'stock.required' => 'تعداد موجودی الزامی است.',
            'stock.integer'  => 'تعداد موجودی باید عدد صحیح باشد.',
            'stock.min'      => 'تعداد موجودی نمی‌تواند کمتر از صفر باشد.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $record = DB::table('pharmacy_medicines')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->first();

        if (!$record) {
            return response()->json([
                'status'  => 'error',
                'message' => 'داروی مورد نظر در انبار داروخانه یافت نشد.',
            ], 404);
        }

        DB::table('pharmacy_medicines')
            ->where('id', $id)
            ->update([
                'stock'      => $request->stock,
                'updated_at' => now(),
            ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'موجودی دارو با موفقیت به‌روزرسانی شد.',
            'data'    => [
                'id'    => (int) $id,
                'stock' => (int) $request->stock,
            ],
        ]);
    }
}
