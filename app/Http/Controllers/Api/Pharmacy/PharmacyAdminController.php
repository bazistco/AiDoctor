<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PharmacyAdminController extends Controller
{
    // دریافت لیست درخواست‌های داروخانه
    public function getRequests(Request $request)
    {
        $pharmacyId = $request->user()->pharmacy_id;

        $status = $request->input('status');
        $page = $request->input('page', 1);
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $query = "
            SELECT
                upr.id,
                upr.status,
                upr.total_price,
                upr.created_at,
                CONCAT(u.first_name, ' ', u.last_name) as patient_name,
                up.prescription_number,
                pt.name as prescription_type
            FROM users_pharmacy_requests upr
            INNER JOIN users u ON upr.user_id = u.id
            LEFT JOIN users_prescriptions up ON upr.prescription_id = up.id
            LEFT JOIN prescription_types pt ON up.prescription_type_id = pt.id
            WHERE upr.pharmacy_id = ?
        ";

        $params = [$pharmacyId];

        if ($status !== null) {
            $query .= " AND upr.status = ?";
            $params[] = $status;
        }

        $query .= " ORDER BY upr.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        $requests = DB::select($query, $params);

        $countQuery = "
            SELECT COUNT(*) as total
            FROM users_pharmacies_requests
            WHERE pharmacy_id = ?" . ($status !== null ? " AND status = ?" : "");

        $countParams = $status !== null ? [$pharmacyId, $status] : [$pharmacyId];
        $total = DB::select($countQuery, $countParams)[0]->total;

        return response()->json([
            'success' => true,
            'data' => $requests,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => ceil($total / $perPage)
            ]
        ]);
    }

    // جزئیات درخواست
    public function getRequestDetail($requestId, Request $request)
    {
        $pharmacyId = $request->user()->pharmacy_id;

        $requestData = DB::select("
            SELECT
                upr.*,
                CONCAT(u.first_name, ' ', u.last_name) as patient_name,
                u.phone as patient_phone,
                up.prescription_number,
                up.prescription_file,
                pt.name as prescription_type,
                d.name as discount_name,
                d.amount as discount_amount,
                d.type as discount_type
            FROM users_pharmacies_requests upr
            INNER JOIN users u ON upr.user_id = u.id
            LEFT JOIN users_prescriptions up ON upr.prescription_id = up.id
            LEFT JOIN prescription_types pt ON up.prescription_type_id = pt.id
            LEFT JOIN discounts d ON upr.discount_id = d.id
            WHERE upr.id = ? AND upr.pharmacy_id = ?
        ", [$requestId, $pharmacyId]);

        if (empty($requestData)) {
            return response()->json(['success' => false, 'message' => 'درخواست یافت نشد'], 404);
        }

        $items = DB::select("
            SELECT
                upri.*,
                pm.name as medicine_name,
                pm.code as medicine_code
            FROM users_pharmacies_requests_items upri
            INNER JOIN pharmacies_medicines pm ON upri.pharmacy_medicine_id = pm.id
            WHERE upri.request_id = ?
        ", [$requestId]);

        return response()->json([
            'success' => true,
            'data' => [
                'request' => $requestData[0],
                'items' => $items
            ]
        ]);
    }

    // تغییر وضعیت درخواست
    public function updateRequestStatus($requestId, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|integer|in:0,1,2,3,4',
            'admin_note' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $pharmacyId = $request->user()->pharmacy_id;

        $exists = DB::select("SELECT id FROM users_pharmacies_requests WHERE id = ? AND pharmacy_id = ?", [$requestId, $pharmacyId]);

        if (empty($exists)) {
            return response()->json(['success' => false, 'message' => 'دسترسی غیرمجاز'], 403);
        }

        DB::update("
            UPDATE users_pharmacies_requests
            SET status = ?, admin_note = ?, updated_at = NOW()
            WHERE id = ?
        ", [$request->status, $request->admin_note, $requestId]);

        return response()->json([
            'success' => true,
            'message' => 'وضعیت با موفقیت بروزرسانی شد'
        ]);
    }

    // مدیریت داروها
    public function getMedicines(Request $request)
    {
        $pharmacyId = $request->user()->pharmacy_id;

        $search = $request->input('search');
        $status = $request->input('status');

        $query = "SELECT * FROM pharmacies_medicines WHERE pharmacy_id = ?";
        $params = [$pharmacyId];

        if ($search) {
            $query .= " AND (name LIKE ? OR code LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        if ($status !== null) {
            $query .= " AND status = ?";
            $params[] = $status;
        }

        $query .= " ORDER BY name ASC";

        $medicines = DB::select($query, $params);

        return response()->json([
            'success' => true,
            'data' => $medicines
        ]);
    }

    // افزودن/ویرایش دارو
    public function storeMedicine(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'description' => 'nullable|string',
            'status' => 'required|integer|in:0,1'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $pharmacyId = $request->user()->pharmacy_id;
        $medicineId = $request->input('id');

        if ($medicineId) {
            DB::update("
                UPDATE pharmacies_medicines
                SET name = ?, code = ?, price = ?, stock = ?, description = ?, status = ?, updated_at = NOW()
                WHERE id = ? AND pharmacy_id = ?
            ", [
                $request->name,
                $request->code,
                $request->price,
                $request->stock,
                $request->description,
                $request->status,
                $medicineId,
                $pharmacyId
            ]);

            return response()->json(['success' => true, 'message' => 'دارو با موفقیت ویرایش شد']);
        } else {
            DB::insert("
                INSERT INTO pharmacies_medicines (pharmacy_id, name, code, price, stock, description, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                $pharmacyId,
                $request->name,
                $request->code,
                $request->price,
                $request->stock,
                $request->description,
                $request->status
            ]);

            return response()->json(['success' => true, 'message' => 'دارو با موفقیت اضافه شد'], 201);
        }
    }

    // حذف دارو
    public function deleteMedicine($medicineId, Request $request)
    {
        $pharmacyId = $request->user()->pharmacy_id;

        $affected = DB::delete("DELETE FROM pharmacies_medicines WHERE id = ? AND pharmacy_id = ?", [$medicineId, $pharmacyId]);

        if ($affected === 0) {
            return response()->json(['success' => false, 'message' => 'دارو یافت نشد'], 404);
        }

        return response()->json(['success' => true, 'message' => 'دارو با موفقیت حذف شد']);
    }

    // آمار داشبورد
    public function getDashboardStats(Request $request)
    {
        $pharmacyId = $request->user()->pharmacy_id;

        $stats = DB::select("
            SELECT
                COUNT(*) as total_requests,
                SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END) as completed,
                SUM(total_price) as total_revenue
            FROM users_pharmacies_requests
            WHERE pharmacy_id = ?
        ", [$pharmacyId]);

        return response()->json([
            'success' => true,
            'data' => $stats[0]
        ]);
    }
}
