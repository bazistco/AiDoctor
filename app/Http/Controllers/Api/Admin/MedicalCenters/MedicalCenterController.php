<?php
// app/Http/Controllers/Admin/MedicalCenters/MedicalCenterController.php

namespace App\Http\Controllers\Api\Admin\MedicalCenters;

use App\Http\Controllers\Api\Admin\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MedicalCenterController extends BaseController
{
    public function index(Request $request)
    {
        $query = DB::table('medical_centers_info as mc')
            ->leftJoin('users as u', 'u.id', '=', 'mc.user_id')
            ->select(
                'mc.id', 'mc.name', 'mc.slug', 'mc.status', 'mc.image',
                'mc.lat', 'mc.lng', 'mc.address',
                'u.name as owner_name', 'u.mobile as owner_mobile'
            );

        if ($search = $request->query('search')) {
            $query->where('mc.name', 'like', "%{$search}%");
        }
        if (!is_null($request->query('status'))) {
            $query->where('mc.status', $request->query('status'));
        }

        return $this->paginated(
            $query->orderByDesc('mc.id')->paginate($request->query('per_page', 15))
        );
    }

    public function show(int $id)
    {
        $center = DB::table('medical_centers_info')->where('id', $id)->first();
        if (!$center) {
            return $this->error('مرکز درمانی یافت نشد', 404);
        }
        return $this->success($center);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|integer|exists:users,id',
            'name'    => 'required|string|max:255',
            'slug'    => 'nullable|string|max:255|unique:medical_centers_info,slug',
            'status'  => 'required|integer|in:0,1',
            'image'   => 'nullable|string|max:255',
            'lat'     => 'nullable|numeric',
            'lng'     => 'nullable|numeric',
            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        $data = $validator->validated();
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']) . '-' . Str::random(5);

        $id = DB::table('medical_centers_info')->insertGetId($data);

        return $this->success(['id' => $id], 'مرکز درمانی ایجاد شد', 201);
    }

    public function update(Request $request, int $id)
    {
        if (!DB::table('medical_centers_info')->where('id', $id)->exists()) {
            return $this->error('مرکز درمانی یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|integer|exists:users,id',
            'name'    => 'sometimes|required|string|max:255',
            'slug'    => "nullable|string|max:255|unique:medical_centers_info,slug,{$id}",
            'status'  => 'sometimes|required|integer|in:0,1',
            'image'   => 'nullable|string|max:255',
            'lat'     => 'nullable|numeric',
            'lng'     => 'nullable|numeric',
            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('medical_centers_info')->where('id', $id)->update($validator->validated());

        return $this->success(null, 'بروزرسانی شد');
    }

    public function destroy(int $id)
    {
        if (!DB::table('medical_centers_info')->where('id', $id)->exists()) {
            return $this->error('مرکز درمانی یافت نشد', 404);
        }

        DB::table('medical_centers_info')->where('id', $id)->delete();

        return $this->success(null, 'حذف شد');
    }
}
