<?php
// app/Http/Controllers/Admin/Labs/LabTestController.php

namespace App\Http\Controllers\Api\Admin\Labs;

use App\Http\Controllers\Api\Admin\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LabTestController extends BaseController
{
    public function index(Request $request)
    {
        $query = DB::table('labs_tests as lt')
            ->join('labs_info as l', 'l.id', '=', 'lt.lab_id')
            ->join('test_packs as tp', 'tp.id', '=', 'lt.test_pack_id')
            ->select(
                'lt.id', 'lt.lab_id', 'lt.test_pack_id', 'lt.price',
                'lt.status', 'lt.description',
                'l.name as lab_name', 'tp.name as test_pack_name'
            );

        if ($labId = $request->query('lab_id')) {
            $query->where('lt.lab_id', $labId);
        }
        if (!is_null($request->query('status'))) {
            $query->where('lt.status', $request->query('status'));
        }

        return $this->paginated(
            $query->orderByDesc('lt.id')->paginate($request->query('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lab_id'       => 'required|integer|exists:labs_info,id',
            'test_pack_id' => 'required|integer|exists:test_packs,id',
            'price'        => 'required|numeric|min:0',
            'status'       => 'required|integer|in:0,1',
            'description'  => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        $id = DB::table('labs_tests')->insertGetId($validator->validated());

        return $this->success(['id' => $id], 'آزمایش ایجاد شد', 201);
    }

    public function update(Request $request, int $id)
    {
        if (!DB::table('labs_tests')->where('id', $id)->exists()) {
            return $this->error('یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'lab_id'       => 'sometimes|required|integer|exists:labs_info,id',
            'test_pack_id' => 'sometimes|required|integer|exists:test_packs,id',
            'price'        => 'sometimes|required|numeric|min:0',
            'status'       => 'sometimes|required|integer|in:0,1',
            'description'  => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('labs_tests')->where('id', $id)->update($validator->validated());

        return $this->success(null, 'بروزرسانی شد');
    }

    public function destroy(int $id)
    {
        if (!DB::table('labs_tests')->where('id', $id)->exists()) {
            return $this->error('یافت نشد', 404);
        }

        DB::table('labs_tests')->where('id', $id)->delete();

        return $this->success(null, 'حذف شد');
    }
}
