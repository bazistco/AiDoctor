<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserLabRequestController extends Controller
{
    /**
     * نمایش جزئیات درخواست آزمایشگاه برای کاربر (بیمار)
     */
    public function show($id)
    {
        $userId = auth()->id();

        // ۱. واکشی درخواست اصلی از جدول users_labs_requests
        $request = DB::table('users_labs_requests as ulr')
            ->where('ulr.id', $id)
            ->where('ulr.user_id', $userId)
            ->select(
                'ulr.id',
                'ulr.lab_id',
                'ulr.address_id',
                'ulr.status',
                'ulr.visit_type',
                'ulr.created_at',
                'ulr.total_price'
            )
            ->first();

        if (!$request) {
            return response()->json(['success' => false, 'message' => 'درخواست یافت نشد'], 404);
        }

        // دریافت آدرس (اختیاری)
        $address = null;
        if ($request->address_id) {
            $address = DB::table('addresses')->where('id', $request->address_id)->value('address');
        }

        // ۲. دریافت نام آزمایشگاه (در صورت اختصاص)
        $labName = 'در انتظار تعیین آزمایشگاه';
        if ($request->lab_id) {
            $labName = DB::table('labs_info')
                ->where('user_id', $request->lab_id)
                ->value('name') ?? 'آزمایشگاه';
        }

        // ۳. واکشی آزمایش‌ها همراه با فایل نتیجه (مشابه ساختار ادمین)
        $baseUrl = 'http://185.222.163.113:7000/'; // آدرس پایه برای فایل‌ها

        $tests = DB::table('lab_request_test_packs as lrtp')
            ->join('labs_tests as lt', 'lt.id', '=', 'lrtp.lab_test_id')   // ارتباط با جدول labs_tests
            ->join('test_packs as tp', 'tp.id', '=', 'lt.test_pack_id')    // دریافت نام تست
            ->leftJoin('lab_request_results as lrr', 'lrr.lab_request_test_pack_id', '=', 'lrtp.id') // نتیجه هر آیتم
            ->where('lrtp.lab_request_id', $id)
            ->select(
                'lrtp.id',
                'tp.name as test_name',
                'lt.price',
                'lrr.file_path as result_file'
            )
            ->get();

        // پردازش تست‌ها و ساخت URL کامل برای فایل نتیجه
        $processedTests = $tests->map(function ($test) use ($baseUrl) {
            $resultFileUrl = null;
            if (!empty($test->result_file)) {
                $resultFileUrl = str_starts_with($test->result_file, 'http')
                    ? $test->result_file
                    : $baseUrl . ltrim($test->result_file, '/');
            }

            return [
                'id'          => $test->id,
                'test_name'   => $test->test_name,
                'price'       => $test->price,
                'result_file' => $resultFileUrl,
            ];
        });

        // ۴. جمع کل قیمت (اگر در جدول ذخیره نشده باشد، از جمع قیمت تست‌ها محاسبه می‌شود)
        $totalPrice = $request->total_price ?? $tests->sum('price');

        return response()->json([
            'success' => true,
            'data' => [
                'id'                => $request->id,
                'status'            => (int) $request->status,
                'status_label'      => $this->statusLabel($request->status),
                'total_price'       => (int) $totalPrice,
                'visit_type'        => (int) $request->visit_type, // 0 = در منزل، 1 = حضوری
                'visit_type_label'  => $request->visit_type == 0 ? 'نمونه‌گیری در منزل' : 'مراجعه حضوری',
                'request_date'      => $request->created_at,
                'lab_name'          => $labName,
                'address'           => $address,
                'tests'             => $processedTests,
            ],
        ]);
    }

    /**
     * پرداخت فاکتور و تغییر وضعیت به "در انتظار نمونه‌گیری (2)"
     */
    public function pay($id)
    {
        $userId = auth()->id();

        // استفاده از جدول صحیح users_labs_requests
        $request = DB::table('users_labs_requests')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$request) {
            return response()->json(['success' => false, 'message' => 'درخواست یافت نشد'], 404);
        }

        // وضعیت ۱ = در انتظار پرداخت
        if ((int) $request->status !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'فقط درخواست‌های با وضعیت "در انتظار پرداخت" قابل پرداخت هستند'
            ], 400);
        }

        // به‌روزرسانی وضعیت به ۲ (در انتظار نمونه‌گیری)
        DB::table('users_labs_requests')
            ->where('id', $id)
            ->update([
                'status' => 2,
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'پرداخت با موفقیت انجام شد. درخواست به مرحله نمونه‌گیری رفت.',
        ]);
    }

    /**
     * دیکشنری وضعیت‌های درخواست آزمایشگاه (هماهنگ با وضعیت‌های جدول)
     */
    private function statusLabel($status)
    {
        return match ((int) $status) {
            0 => 'درخواست جدید',
            1 => 'در انتظار پرداخت',
            2 => 'در انتظار نمونه‌گیری',
            3 => 'در انتظار اعلام نتیجه',
            4, 5 => 'تکمیل شده',
            6 => 'لغو شده',
            default => 'نامشخص',
        };
    }
}
