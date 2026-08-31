<?php
// app/Http/Controllers/Api/Owner/MedicalCenters/CoverageController.php

namespace App\Http\Controllers\Api\Owner\MedicalCenters;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CoverageController extends Controller
{
    use ApiResponse;

    public function getCoverage(Request $request)
    {
        $medicalCenterId = $request->medical_center_id;

        // تغییر نام جدول به medical_centers_info
        $center = DB::table('medical_centers_info')
            ->where('user_id', $medicalCenterId) // اگر ارتباط با user_id است، این را به user_id تغییر دهید
            ->select('coverage_radius', 'coverage_description')
            ->first();

        if (!$center) {
            return $this->error('مرکز درمانی یافت نشد', 404);
        }

        $selectedRegions = DB::table('medical_center_regions')
            ->where('medical_center_id', $medicalCenterId)
            ->pluck('region_id')
            ->toArray();

        return $this->success([
            'coverage_radius'      => $center->coverage_radius,
            'coverage_description' => $center->coverage_description,
            'selectedAreaIds'      => $selectedRegions,
        ]);
    }

    public function updateCoverage(Request $request)
    {
        $medicalCenterId = $request->medical_center_id;

        $validator = Validator::make($request->all(), [
            'coverage_radius'      => 'nullable|numeric|min:0',
            'coverage_description' => 'nullable|string|max:1000',
            'selectedAreaIds'      => 'nullable|array',
            'selectedAreaIds.*'    => 'integer|exists:regions,id',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        try {
            DB::beginTransaction();

            // تغییر نام جدول به medical_centers_info
            DB::table('medical_centers_info')
                ->where('user_id', $medicalCenterId) // اگر ارتباط با user_id است، این را به user_id تغییر دهید
                ->update([
                    'coverage_radius'      => $request->input('coverage_radius'),
                    'coverage_description' => $request->input('coverage_description'),
                    'updated_at'           => now(),
                ]);

            DB::table('medical_center_regions')
                ->where('medical_center_id', $medicalCenterId)
                ->delete();

            $regionsToInsert = [];
            if ($request->has('selectedAreaIds') && is_array($request->selectedAreaIds)) {
                foreach ($request->selectedAreaIds as $regionId) {
                    $regionsToInsert[] = [
                        'medical_center_id' => $medicalCenterId,
                        'region_id'         => $regionId,
                    ];
                }

                if (!empty($regionsToInsert)) {
                    DB::table('medical_center_regions')->insert($regionsToInsert);
                }
            }

            DB::commit();

            return $this->success(null, 'تنظیمات پوشش با موفقیت ذخیره شد');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('خطا در ذخیره‌سازی اطلاعات: ' . $e->getMessage(), 500);
        }
    }

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
}
