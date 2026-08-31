<?php
// app/Http/Controllers/Admin/Pharmacies/PharmacyController.php

namespace App\Http\Controllers\Api\Admin\Pharmacies;

use App\Http\Controllers\Api\Admin\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PharmacyController extends BaseController
{
    public function index(Request $request)
    {
        $query = DB::table('pharmacies_info as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->select(
                'p.id', 'p.name', 'p.slug', 'p.status', 'p.image',
                'p.lat', 'p.lng', 'p.address', 'p.created_at',
                'u.name as owner_name', 'u.mobile as owner_mobile'
            );

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'like', "%{$search}%")
                    ->orWhere('p.slug', 'like', "%{$search}%");
            });
        }
        if (!is_null($request->query('status'))) {
            $query->where('p.status', $request->query('status'));
        }

        return $this->paginated(
            $query->orderByDesc('p.id')->paginate($request->query('per_page', 15))
        );
    }

    public function show(int $id)
    {
        $pharmacy = DB::table('pharmacies_info as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.id', $id)
            ->select('p.*', 'u.name as owner_name', 'u.mobile as owner_mobile')
            ->first();

        if (!$pharmacy) {
            return $this->error('داروخانه یافت نشد', 404);
        }

        return $this->success($pharmacy);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|integer|exists:users,id',
            'name'    => 'required|string|max:255',
            'slug'    => 'nullable|string|max:255|unique:pharmacies_info,slug',
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
        $data['created_at'] = now();

        $id = DB::table('pharmacies_info')->insertGetId($data);

        return $this->success(['id' => $id], 'داروخانه ایجاد شد', 201);
    }

    public function update(Request $request, int $id)
    {
        if (!DB::table('pharmacies_info')->where('id', $id)->exists()) {
            return $this->error('داروخانه یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|integer|exists:users,id',
            'name'    => 'sometimes|required|string|max:255',
            'slug'    => "nullable|string|max:255|unique:pharmacies_info,slug,{$id}",
            'status'  => 'sometimes|required|integer|in:0,1',
            'image'   => 'nullable|string|max:255',
            'lat'     => 'nullable|numeric',
            'lng'     => 'nullable|numeric',
            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('pharmacies_info')->where('id', $id)->update($validator->validated());

        return $this->success(null, 'داروخانه بروزرسانی شد');
    }

    public function destroy(int $id)
    {
        if (!DB::table('pharmacies_info')->where('id', $id)->exists()) {
            return $this->error('داروخانه یافت نشد', 404);
        }

        DB::table('pharmacies_info')->where('id', $id)->delete();

        return $this->success(null, 'داروخانه حذف شد');
    }
}
