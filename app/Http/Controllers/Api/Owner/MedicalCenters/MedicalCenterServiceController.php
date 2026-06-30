<?php

namespace App\Http\Controllers\Api\Owner\MedicalCenters;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MedicalCenterServiceController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $services = DB::table('medical_center_services')
            ->join('medical_services', 'medical_center_services.medical_service_id', '=', 'medical_services.id')
            ->where('medical_center_services.medical_center_id', $request->medical_center_id)
            ->where('medical_center_services.status', 1)
            ->select(
                'medical_center_services.*',
                'medical_services.name as service_name',
                'medical_services.slug as service_slug'
            )
            ->orderByDesc('medical_center_services.id')
            ->paginate($request->query('per_page', 15));

        return $this->paginated($services);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'medical_service_id' => 'required|exists:medical_services,id',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'date' => 'nullable|date',
            'status' => 'sometimes|in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        // بررسی تکراری نبودن
        $exists = DB::table('medical_center_services')
            ->where('medical_center_id', $request->medical_center_id)
            ->where('medical_service_id', $request->medical_service_id)
            ->exists();

        if ($exists) {
            return $this->error('این سرویس قبلاً اضافه شده است', 422);
        }

        $id = DB::table('medical_center_services')->insertGetId([
            'medical_center_id' => $request->medical_center_id,
            'medical_service_id' => $request->medical_service_id,
            'description' => $request->description,
            'price' => $request->price,
            'date' => $request->date,
            'status' => $request->status ?? 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->success(['id' => $id], 'خدمت اضافه شد', 201);
    }

    public function show(Request $request, int $id)
    {
        $service = DB::table('medical_center_services')
            ->join('medical_services', 'medical_center_services.medical_service_id', '=', 'medical_services.id')
            ->where('medical_center_services.medical_center_id', $request->medical_center_id)
            ->where('medical_center_services.id', $id)
            ->select(
                'medical_center_services.*',
                'medical_services.name as service_name',
                'medical_services.slug as service_slug'
            )
            ->first();

        if (!$service) {
            return $this->error('خدمت یافت نشد', 404);
        }

        return $this->success($service);
    }

    public function update(Request $request, int $id)
    {
        $service = DB::table('medical_center_services')
            ->where('medical_center_id', $request->medical_center_id)
            ->where('id', $id)
            ->first();

        if (!$service) {
            return $this->error('خدمت یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'medical_service_id' => 'sometimes|exists:medical_services,id',
            'description' => 'nullable|string',
            'price' => 'sometimes|numeric|min:0',
            'date' => 'nullable|date',
            'status' => 'sometimes|in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        // بررسی تکراری نبودن
        if ($request->has('medical_service_id') && $request->medical_service_id != $service->medical_service_id) {
            $exists = DB::table('medical_center_services')
                ->where('medical_center_id', $request->medical_center_id)
                ->where('medical_service_id', $request->medical_service_id)
                ->where('id', '!=', $id)
                ->exists();

            if ($exists) {
                return $this->error('این سرویس قبلاً اضافه شده است', 422);
            }
        }

        DB::table('medical_center_services')
            ->where('id', $id)
            ->where('medical_center_id', $request->medical_center_id)
            ->update(array_filter([
                'medical_service_id' => $request->medical_service_id,
                'description' => $request->description,
                'price' => $request->price,
                'date' => $request->date,
                'status' => $request->status,
                'updated_at' => now(),
            ], fn($v) => $v !== null));

        return $this->success(null, 'خدمت بروزرسانی شد');
    }

    public function destroy(Request $request, int $id)
    {
        $service = DB::table('medical_center_services')
            ->where('medical_center_id', $request->medical_center_id)
            ->where('id', $id)
            ->first();

        if (!$service) {
            return $this->error('خدمت یافت نشد', 404);
        }

        // بررسی استفاده در درخواست‌های فعال
        $hasActiveRequests = DB::table('user_medical_center_request_services')
            ->join('users_medical_center_requests', 'user_medical_center_request_services.medical_center_request_id', '=', 'users_medical_center_requests.id')
            ->where('user_medical_center_request_services.medical_center_service_id', $id)
            ->whereIn('users_medical_center_requests.status', [1, 2])
            ->exists();

        if ($hasActiveRequests) {
            return $this->error('این سرویس در درخواست‌های فعال استفاده شده و قابل حذف نیست', 422);
        }

        DB::table('medical_center_services')
            ->where('id', $id)
            ->where('medical_center_id', $request->medical_center_id)
            ->delete();

        return $this->success(null, 'خدمت حذف شد');
    }
}
