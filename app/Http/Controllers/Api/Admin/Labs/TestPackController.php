<?php
// app/Http/Controllers/Admin/Labs/TestPackController.php

namespace App\Http\Controllers\Api\Admin\Labs;

use App\Http\Controllers\Api\Admin\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TestPackController extends BaseController
{
    public function index(Request $request)
    {
        $query = DB::table('test_packs');

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }
        if (!is_null($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        return $this->paginated(
            $query->orderByDesc('id')->paginate($request->query('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'   => 'required|string|max:255',
            'status' => 'required|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        $id = DB::table('test_packs')->insertGetId($validator->validated());

        return $this->success(['id' => $id], 'پک آزمایش ایجاد شد', 201);
    }

    public function update(Request $request, int $id)
    {
        if (!DB::table('test_packs')->where('id', $id)->exists()) {
            return $this->error('یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'name'   => 'sometimes|required|string|max:255',
            'status' => 'sometimes|required|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('test_packs')->where('id', $id)->update($validator->validated());

        return $this->success(null, 'بروزرسانی شد');
    }

    public function destroy(int $id)
    {
        if (!DB::table('test_packs')->where('id', $id)->exists()) {
            return $this->error('یافت نشد', 404);
        }

        DB::table('test_packs')->where('id', $id)->delete();

        return $this->success(null, 'حذف شد');
    }
}
