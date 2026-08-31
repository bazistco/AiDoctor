<?php

namespace App\Http\Controllers\Api\Owner\Labs;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LabTestController extends Controller
{
    use ApiResponse;

    public function getTestPacks()
    {
        $testPacks = DB::table('test_packs')
            ->where('status', 1)
            ->select('id', 'name')
            ->orderBy('id')
            ->get();

        return $this->success($testPacks);
    }

    public function index(Request $request)
    {
        $labId = $request->lab_id;

        $query = DB::table('labs_tests as lt')
            ->join('test_packs as tp', 'tp.id', '=', 'lt.test_pack_id')
            ->where('lt.lab_id', $labId)
            ->select('lt.id', 'lt.test_pack_id', 'lt.price', 'lt.status', 'lt.description', 'tp.name as test_pack_name');

        if (!is_null($request->query('status'))) {
            $query->where('lt.status', $request->query('status'));
        }

        return $this->paginated(
            $query->orderByDesc('lt.id')->paginate($request->query('per_page', 15))
        );
    }

    public function show(Request $request, int $id)
    {
        $labId = $request->lab_id;

        $test = DB::table('labs_tests as lt')
            ->join('test_packs as tp', 'tp.id', '=', 'lt.test_pack_id')
            ->where('lt.lab_id', $labId)
            ->where('lt.id', $id)
            ->select('lt.id', 'lt.test_pack_id', 'lt.price', 'lt.status', 'lt.description', 'tp.name as test_pack_name')
            ->first();

        if (!$test) {
            return $this->error('آزمایش یافت نشد', 404);
        }

        return $this->success($test);
    }

    public function store(Request $request)
    {
        $labId = $request->lab_id;

        $validator = Validator::make($request->all(), [
            'test_pack_id' => 'required|integer|exists:test_packs,id',
            'price'        => 'required|numeric|min:0',
            'status'       => 'required|integer|in:0,1',
            'description'  => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        $data = $validator->validated();

        // check duplicate
        $exists = DB::table('labs_tests')
            ->where('lab_id', $labId)
            ->where('test_pack_id', $data['test_pack_id'])
            ->exists();

        if ($exists) {
            return $this->error('این آزمایش قبلاً برای این آزمایشگاه ثبت شده است', 409);
        }

        $data['lab_id'] = $labId;

        $id = DB::table('labs_tests')->insertGetId($data);

        return $this->success(['id' => $id], 'آزمایش اضافه شد', 201);
    }


    public function update(Request $request, int $id)
    {
        $labId = $request->lab_id;

        $exists = DB::table('labs_tests')->where('id', $id)->where('lab_id', $labId)->exists();
        if (!$exists) {
            return $this->error('آزمایش یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'test_pack_id' => 'sometimes|required|integer|exists:test_packs,id',
            'price'        => 'sometimes|required|numeric|min:0',
            'status'       => 'sometimes|required|integer|in:0,1',
            'description'  => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }
        if (isset($data['test_pack_id'])) {
            $duplicate = DB::table('labs_tests')
                ->where('lab_id', $labId)
                ->where('test_pack_id', $data['test_pack_id'])
                ->where('id', '!=', $id)
                ->exists();

            if ($duplicate) {
                return $this->error('این آزمایش قبلاً ثبت شده است', 409);
            }
        }

        DB::table('labs_tests')->where('id', $id)->where('lab_id', $labId)
            ->update($validator->validated());

        return $this->success(null, 'بروزرسانی شد');
    }

    public function destroy(Request $request, int $id)
    {
        $labId = $request->lab_id;

        $deleted = DB::table('labs_tests')->where('id', $id)->where('lab_id', $labId)->delete();
        if (!$deleted) {
            return $this->error('آزمایش یافت نشد', 404);
        }

        return $this->success(null, 'حذف شد');
    }
}
