<?php
// app/Http/Controllers/Owner/MedicalCenters/MedicalCenterProfileController.php

namespace App\Http\Controllers\Api\Owner\MedicalCenters;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MedicalCenterProfileController extends Controller
{
    use ApiResponse;

    public function show(Request $request)
    {
        return $this->success(DB::table('medical_centers_info')->where('id', $request->medical_center_id)->first());
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'    => 'sometimes|required|string|max:255',
            'image'   => 'nullable|string|max:255',
            'lat'     => 'nullable|numeric',
            'lng'     => 'nullable|numeric',
            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('medical_centers_info')->where('id', $request->medical_center_id)->update($validator->validated());

        return $this->success(null, 'پروفایل مرکز درمانی بروزرسانی شد');
    }
}
