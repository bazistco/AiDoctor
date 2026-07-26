<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UserPharmacyRequestController extends Controller
{
    /**
     * نمایش جزئیات درخواست داروخانه به همراه فاکتور
     */
    public function show($id)
    {
        $userId = auth()->id();

        $request = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$request) {
            return response()->json(['success' => false, 'message' => 'درخواست یافت نشد'], 404);
        }

        if ($request->status < 1) {
            return response()->json([
                'success' => false,
                'message' => 'این درخواست هنوز تأیید نشده و فاکتوری ندارد',
            ], 400);
        }

        // دریافت اقلام فاکتور
        $items = DB::table('user_pharmacy_request_medicines as uprm')
            ->join('pharmacy_medicines as pm', 'uprm.pharmacy_medicine_id', '=', 'pm.id')
            ->join('medicines as m', 'pm.medicine_id', '=', 'm.id')
            ->where('uprm.user_pharmacy_request_id', $id)
            ->select(
                'uprm.id',
                'm.name as medicine_name',
                'uprm.quantity',
                'uprm.price as unit_price',
                DB::raw('(uprm.quantity * uprm.price) as total_price'),
                'pm.unit'
            )
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $request->id,
                'status' => $request->status,
                'status_label' => $this->statusLabel($request->status),
                'total_price' => $request->total_price,
                'created_at' => $request->created_at,
                'pharmacy_name' => DB::table('pharmacies_info')->where('user_id', $request->pharmacy_id)->value('name'),
                'medicines' => $items,
            ],
        ]);
    }

    /**
     * پرداخت و انتقال وضعیت از ۱ به ۲
     */
    public function pay($id)
    {
        $userId = auth()->id();

        $request = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$request) {
            return response()->json(['success' => false, 'message' => 'درخواست یافت نشد'], 404);
        }

        if ($request->status != 1) {
            return response()->json(['success' => false, 'message' => 'فقط درخواست‌های با وضعیت "در انتظار پرداخت" قابل پرداخت هستند'], 400);
        }

        DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->update(['status' => 2, 'updated_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'پرداخت با موفقیت انجام شد. سفارش به مرحله آماده‌سازی رفت.',
        ]);
    }

    private function statusLabel($status)
    {
        return match ((int)$status) {
            1 => 'در انتظار پرداخت',
            2 => 'در حال آماده‌سازی',
            3 => 'آماده ارسال',
            4 => 'در حال ارسال',
            5 => 'تحویل شده',
            6 => 'تکمیل شده',
            7 => 'لغو شده',
            default => 'نامشخص',
        };
    }
    public function storeRequest(Request $request)
    {
        $userId = auth()->id();

        // اعتبارسنجی
        $validator = Validator::make($request->all(), [
            'delivery_type' => 'required|in:0,1', // 0: حضوری, 1: ارسال
            'user_address_id' => 'required_if:delivery_type,1|nullable|integer',
            'prescription_code' => 'nullable|string',
            'medicines_text' => 'nullable|string',
            'prescription_image' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'has_insurance' => 'nullable|boolean',
            'description' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        // ۱. تعیین نوع نسخه (prescription_type_id)
        $prescriptionTypeId = 1; // پیش‌فرض: 1 (بدون نسخه / متنی)
        if ($request->hasFile('prescription_image')) {
            $prescriptionTypeId = 3; // 3: فایل نسخه
        } elseif ($request->filled('prescription_code')) {
            $prescriptionTypeId = 2; // 2: نسخه دیجیتال
        }

        // ۲. پردازش آپلود فایل
        $uploadedFiles = [];
        if ($request->hasFile('prescription_image')) {
            $path = $request->file('prescription_image')->store('prescriptions', 'public');
            $uploadedFiles[] = $path;
        }

        // ۳. ساختاردهی فیلد details به صورت JSON
        $details = [
            'code' => $request->input('prescription_code'),
            'medicines' => $request->input('medicines_text'), // نام داروهای دستی
            'files' => $uploadedFiles,
            'delivery_type' => $request->input('delivery_type'),
            'user_address_id' => $request->input('user_address_id'),
            'has_insurance' => $request->input('has_insurance', 0),
            'description' => $request->input('description')
        ];

        DB::beginTransaction();
        try {
            // ۴. ثبت اطلاعات در جدول users_prescriptions
            $prescriptionId = DB::table('users_prescriptions')->insertGetId([
                'user_id' => $userId,
                'prescription_type_id' => $prescriptionTypeId,
                'status' => 1,
                'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // ۵. ثبت درخواست در جدول users_pharmacy_requests
            $requestId = DB::table('users_pharmacy_requests')->insertGetId([
                'user_id' => $userId,
                'pharmacy_id' => null, // Null = درخواست آزاد برای همه داروخانه‌ها
                'pharmacy_request_type_id' => null,
                'prescription_id' => $prescriptionId,
                'total_price' => null,
                'status' => 0, // 0: در انتظار بررسی
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'درخواست شما با موفقیت برای داروخانه‌های اطراف ارسال شد.',
                'data' => [
                    'request_id' => $requestId,
                    'prescription_id' => $prescriptionId
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'خطا در ثبت درخواست.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
