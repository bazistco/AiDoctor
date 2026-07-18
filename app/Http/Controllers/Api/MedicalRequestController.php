<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class MedicalRequestController extends Controller
{
    /**
     * مرحله ۱: دریافت لیست خدمات
     */
    public function getServices()
    {
        $services = DB::table('medical_services')
            ->where('status', 1)
            ->select('id', 'name', 'slug')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $services
        ]);
    }

    /**
     * مرحله ۳: دریافت لیست درمانگاه‌هایی که خدمات درخواستی را ارائه می‌دهند
     */
    public function getCenters(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'service_ids' => 'required|array',
            'service_ids.*' => 'integer|exists:medical_services,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $serviceIds = $request->input('service_ids');

        // اصلاح: اتصال جداول با استفاده از medical_centers_info.user_id
        $centers = DB::table('medical_centers_info')
            ->join('medical_center_services', 'medical_centers_info.user_id', '=', 'medical_center_services.medical_center_id')
            ->whereIn('medical_center_services.medical_service_id', $serviceIds)
            ->where('medical_center_services.status', 1)
            ->select(
                'medical_centers_info.user_id as id', // برگرداندن user_id با نام id برای هماهنگی با فرانت‌اند
                'medical_centers_info.name',
                'medical_centers_info.address',
                'medical_centers_info.lat',
                'medical_centers_info.lng',
                DB::raw('SUM(medical_center_services.price) as total_estimated_price')
            )
            ->groupBy(
                'medical_centers_info.user_id', // Group by بر اساس user_id
                'medical_centers_info.name',
                'medical_centers_info.address',
                'medical_centers_info.lat',
                'medical_centers_info.lng'
            )
            ->get();

        return response()->json([
            'success' => true,
            'data' => $centers
        ]);
    }

    /**
     * ثبت نهایی درخواست پرستار در منزل
     */
    public function storeRequest(Request $request)
    {
        // اصلاح: بررسی وجود medical_center_id در فیلد user_id از جدول medical_centers_info
        $validator = Validator::make($request->all(), [
            'medical_center_id' => 'required|integer|exists:medical_centers_info,user_id',
            'service_ids' => 'required|array',
            'service_ids.*' => 'integer|exists:medical_services,id',
            'gender_pref' => 'nullable|string',
            'condition' => 'nullable|string',
            'is_urgent' => 'boolean',
            'address' => 'required|string',
            'time_type_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $userId = auth()->id() ?? 1;
        $now = Carbon::now();

        DB::beginTransaction();
        try {
            // محاسبه قیمت کل سرویس‌های انتخاب شده برای این مرکز
            $services = DB::table('medical_center_services')
                ->where('medical_center_id', $request->medical_center_id)
                ->whereIn('medical_service_id', $request->service_ids)
                ->get();

            $totalPrice = $services->sum('price');

            // ذخیره اطلاعات اضافه
            $extraInfo = json_encode([
                'gender_pref' => $request->gender_pref,
                'condition' => $request->condition,
                'is_urgent' => $request->is_urgent ?? false,
                'custom_address' => $request->address
            ], JSON_UNESCAPED_UNICODE);

            // ایجاد درخواست اصلی
            $requestId = DB::table('user_medical_center_requests')->insertGetId([
                'user_id' => $userId,
                'medical_center_id' => $request->medical_center_id,
                'address_id' => null,
                'time_type_id' => $request->time_type_id,

                // اصلاح این خط: ارسال تاریخ و زمان کامل
                'start_time' => $now->toDateTimeString(),

                'total_price' => $totalPrice,
                'status' => 0,
                'extra_info' => $extraInfo,
                'created_at' => $now,
                'updated_at' => $now,
            ]);



            // ثبت رکوردهای سرویس‌های مربوط به این درخواست
            $requestServicesData = [];
            foreach ($services as $service) {
                $requestServicesData[] = [
                    'user_medical_center_request_id' => $requestId,
                    'medical_center_service_id' => $service->id,
                    'price' => $service->price,
                    'date' => $now->toDateString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('user_medical_center_request_services')->insert($requestServicesData);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'درخواست شما با موفقیت ثبت شد.',
                'data' => [
                    'request_id' => $requestId,
                    'total_price' => $totalPrice
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'خطا در ثبت درخواست',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
