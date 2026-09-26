<?php
// app/Http/Controllers/Api/Owner/MedicalCenters/CoverageController.php

namespace App\Http\Controllers\Api\Owner\MedicalCenters;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;

class CoverageController extends Controller
{
    use ApiResponse;

    // ۱. دریافت لیست مناطق پایه از دیتابیس (بدون تغییر)
    public function getAvailableRegions(Request $request)
    {
        $cityId = $request->query('city_id', 1);

        $regions = DB::table('regions')
            ->where('city_id', $cityId)
            ->select('id', 'name')
            ->orderBy('name', 'asc')
            ->get();

        return $this->success($regions);
    }

    // ۲. دریافت تمامی قوانین و محدوده از ردیس
    public function getRules(Request $request)
    {
        // دریافت شناسه مرکز درمانی (با توجه به منطق پروژه شما)
        $medicalCenterId = $request->medical_center_id ?? $request->user()->id;

        if (!$medicalCenterId) {
            return $this->error('مرکز درمانی یافت نشد', 404);
        }

        $redisKey = "medical_center_rules:{$medicalCenterId}";
        $rules = Redis::get($redisKey);

        return $this->success(
            $rules ? json_decode($rules, true) : null
        );
    }

    // ۳. ذخیره تمامی قوانین و محدوده در ردیس به صورت یکجا
    public function saveRules(Request $request)
    {
        $medicalCenterId = $request->medical_center_id ?? $request->user()->id;

        if (!$medicalCenterId) {
            return $this->error('مرکز درمانی یافت نشد', 404);
        }

        // اعتبارسنجی تمامی فیلدها
        $validator = Validator::make($request->all(), [
            'active_days'          => 'required|array',
            'active_days.*'        => 'integer|min:0|max:6',
            'work_hours'           => 'required|array',
            'work_hours.start'     => 'required|string',
            'work_hours.end'       => 'required|string',
            'coverage_description' => 'nullable|string|max:1000',
            'coverage_radius'      => 'required|integer|min:1|max:50',
            'selectedAreaIds'      => 'nullable|array',
            'selectedAreaIds.*'    => 'integer',
            'map_lat'              => 'required|numeric',
            'map_lng'              => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        $redisKey = "medical_center_rules:{$medicalCenterId}";

        // ذخیره کل دیتای تایید شده در ردیس
        Redis::set($redisKey, json_encode($validator->validated(), JSON_UNESCAPED_UNICODE));

        return $this->success(null, 'قوانین و محدوده خدمت‌رسانی با موفقیت ذخیره شد');
    }
}
