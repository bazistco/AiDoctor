<?php
// app/Http/Controllers/Admin/Labs/LabRequestTypeController.php

namespace App\Http\Controllers\Api\Admin\Labs;

use App\Http\Controllers\Api\Admin\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LabRequestTypeController extends BaseController
{
    public function index(Request $request)
    {
        return $this->paginated(
            DB::table('lab_request_types')->orderByDesc('id')
                ->paginate($request->query('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'   => 'required|string|max:255',
            'slug'   => 'nullable|string|max:255|unique:lab_request_types,slug',
            'status' => 'required|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        $data = $validator->validated();
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);

        $id = DB::table('lab_request_types')->insertGetId($data);

        return $this->success(['id' => $id], 'نوع درخواست ایجاد شد', 201);
    }

    public function update(Request $request, int $id)
    {
        if (!DB::table('lab_request_types')->where('id', $id)->exists()) {
            return $this->error('یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'name'   => 'sometimes|required|string|max:255',
            'slug'   => "nullable|string|max:255|unique:lab_request_types,slug,{$id}",
            'status' => 'sometimes|required|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('lab_request_types')->where('id', $id)->update($validator->validated());

        return $this->success(null, 'بروزرسانی شد');
    }

    public function destroy(int $id)
    {
        if (!DB::table('lab_request_types')->where('id', $id)->exists()) {
            return $this->error('یافت نشد', 404);
        }

        DB::table('lab_request_types')->where('id', $id)->delete();

        return $this->success(null, 'حذف شد');
    }
}
