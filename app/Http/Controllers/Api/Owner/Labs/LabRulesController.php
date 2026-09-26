<?php
// app/Http/Controllers/Api/Owner/Lab/LabRulesController.php

namespace App\Http\Controllers\Api\Owner\Lab;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;

class LabRulesController extends Controller
{
    use ApiResponse; // فرض بر این است که یک Trait برای فرمت خروجی دارید، در غیر این صورت دستی json برگردانید.

    // ۱. لیست مناطق (استان/شهر مربوطه)
    public function getRegions(Request $request)
    {
        $cityId = $request->query('city_id', 1);

        $regions = DB::table('regions')
            ->where('city_id', $cityId)
            ->select('id', 'name')
            ->orderBy('name', 'asc')
            ->get();

        return $this->success($regions);
    }

    // ۲. دریافت اطلاعات ذخیره شده‌ی آزمایشگاه (شیفت، روزها، نقشه)
    public function getRules(Request $request)
    {
        // شناسه صاحب آزمایشگاه از توکن خوانده می‌شود
        $labId = $request->user()->id;

        $redisKey = "lab_rules_config:{$labId}";
        $rules = Redis::get($redisKey);

        return $this->success(
            $rules ? json_decode($rules, true) : null
        );
    }

    // ۳. ذخیره‌سازی جامع تنظیمات (شیفت‌ها، شعاع، مناطق، مرکز نقشه)
    public function saveRules(Request $request)
    {
        $labId = $request->user()->id;

        // اعتبارسنجی جامع تمامی فیلدهای ارسال شده از فرانت‌اند
        $validator = Validator::make($request->all(), [
            // اعتبارسنجی روزهای هفته
            'active_days'          => 'required|array',
            'active_days.*'        => 'integer|min:0|max:6',

            // اعتبارسنجی آرایه شیفت‌ها (کلیدها: 1، 2، 3)
            'shifts'               => 'required|array',
            'shifts.*.isActive'    => 'required|boolean',
            'shifts.*.start'       => 'required_if:shifts.*.isActive,true|string',
            'shifts.*.end'         => 'required_if:shifts.*.isActive,true|string',
            'shifts.*.capacity'    => 'required_if:shifts.*.isActive,true|integer|min:0',

            // اعتبارسنجی تنظیمات محدوده نقشه و سرویس‌دهی
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

        // مطمئن می‌شویم حداقل یک شیفت فعال است
        $validated = $validator->validated();
        $hasActiveShift = false;
        foreach ($validated['shifts'] as $shift) {
            if ($shift['isActive']) {
                $hasActiveShift = true;
                break;
            }
        }

        if (!$hasActiveShift) {
            return $this->error('حداقل یک شیفت کاری باید فعال باشد', 422);
        }

        $redisKey = "lab_rules_config:{$labId}";

        // ذخیره اطلاعات تایید شده در Redis
        // در صورت نیاز به ذخیره دائم، می‌توانید در دیتابیس MySQL (مثلا جدول lab_configs) ذخیره کنید.
        Redis::set($redisKey, json_encode($validated, JSON_UNESCAPED_UNICODE));

        return $this->success(null, 'قوانین و شیفت‌های آزمایشگاه با موفقیت ذخیره شد');
    }
}
