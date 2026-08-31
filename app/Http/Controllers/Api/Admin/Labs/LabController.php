<?php
// app/Http/Controllers/Admin/Labs/LabController.php

namespace App\Http\Controllers\Api\Admin\Labs;

use App\Http\Controllers\Api\Admin\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LabController extends BaseController
{
    public function index(Request $request)
    {
        $query = DB::table('labs_info as l')
            ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->select(
                'l.id', 'l.name', 'l.slug', 'l.status', 'l.image',
                'l.lat', 'l.lng', 'l.address', 'l.created_at',
                'u.name as owner_name', 'u.mobile as owner_mobile'
            );

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('l.name', 'like', "%{$search}%")
                    ->orWhere('l.slug', 'like', "%{$search}%")
                    ->orWhere('u.mobile', 'like', "%{$search}%");
            });
        }

        if (!is_null($request->query('status'))) {
            $query->where('l.status', $request->query('status'));
        }

        $labs = $query->orderByDesc('l.id')
            ->paginate($request->query('per_page', 15));

        return $this->paginated($labs);
    }

    public function show(int $id)
    {
        $lab = DB::table('labs_info as l')
            ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->where('l.id', $id)
            ->select('l.*', 'u.name as owner_name', 'u.mobile as owner_mobile')
            ->first();

        if (!$lab) {
            return $this->error('آزمایشگاه یافت نشد', 404);
        }

        return $this->success($lab);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|integer|exists:users,id',
            'name'    => 'required|string|max:255',
            'slug'    => 'nullable|string|max:255|unique:labs_info,slug',
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

        $id = DB::table('labs_info')->insertGetId($data);

        return $this->success(['id' => $id], 'آزمایشگاه ایجاد شد', 201);
    }

    public function update(Request $request, int $id)
    {
        $exists = DB::table('labs_info')->where('id', $id)->exists();
        if (!$exists) {
            return $this->error('آزمایشگاه یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|integer|exists:users,id',
            'name'    => 'sometimes|required|string|max:255',
            'slug'    => "nullable|string|max:255|unique:labs_info,slug,{$id}",
            'status'  => 'sometimes|required|integer|in:0,1',
            'image'   => 'nullable|string|max:255',
            'lat'     => 'nullable|numeric',
            'lng'     => 'nullable|numeric',
            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('labs_info')->where('id', $id)->update($validator->validated());

        return $this->success(null, 'آزمایشگاه بروزرسانی شد');
    }

    public function destroy(int $id)
    {
        $exists = DB::table('labs_info')->where('id', $id)->exists();
        if (!$exists) {
            return $this->error('آزمایشگاه یافت نشد', 404);
        }

        DB::table('labs_info')->where('id', $id)->delete();

        return $this->success(null, 'آزمایشگاه حذف شد');
    }
}
