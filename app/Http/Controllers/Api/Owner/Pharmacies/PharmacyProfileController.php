<?php
// app/Http/Controllers/Owner/Pharmacies/PharmacyProfileController.php

namespace App\Http\Controllers\Api\Owner\Pharmacies;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PharmacyProfileController extends Controller
{
    use ApiResponse;

    public function show(Request $request)
    {
        return $this->success(DB::table('pharmacies_info')->where('id', $request->pharmacy_id)->first());
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

        DB::table('pharmacies_info')->where('id', $request->pharmacy_id)->update($validator->validated());

        return $this->success(null, 'پروفایل داروخانه بروزرسانی شد');
    }
}
