<?php
// app/Http/Controllers/Admin/Pharmacies/MedicineController.php

namespace App\Http\Controllers\Api\Admin\Pharmacies;

use App\Http\Controllers\Api\Admin\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MedicineController extends BaseController
{
    public function index(Request $request)
    {
        $query = DB::table('medicines');

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        return $this->paginated(
            $query->orderByDesc('id')->paginate($request->query('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:medicines,slug',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        $data = $validator->validated();
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']) . '-' . Str::random(5);
        $data['created_at'] = now();

        $id = DB::table('medicines')->insertGetId($data);

        return $this->success(['id' => $id], 'دارو ایجاد شد', 201);
    }

    public function update(Request $request, int $id)
    {
        if (!DB::table('medicines')->where('id', $id)->exists()) {
            return $this->error('یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'slug' => "nullable|string|max:255|unique:medicines,slug,{$id}",
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('medicines')->where('id', $id)->update($validator->validated());

        return $this->success(null, 'بروزرسانی شد');
    }

    public function destroy(int $id)
    {
        if (!DB::table('medicines')->where('id', $id)->exists()) {
            return $this->error('یافت نشد', 404);
        }

        DB::table('medicines')->where('id', $id)->delete();

        return $this->success(null, 'حذف شد');
    }
}
