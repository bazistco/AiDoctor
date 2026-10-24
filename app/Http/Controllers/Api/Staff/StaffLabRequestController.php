<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class StaffLabRequestController extends Controller
{
    /**
     * لیست درخواست‌های آزمایشگاه با فیلتر و صفحه‌بندی
     */
    public function index(Request $request): JsonResponse
    {
        // دریافت شناسه آزمایشگاه از ویژگی‌های تزریق‌شده توسط میدل‌ویر EnsureCenterStaffAccess
        $labId = (int) $request->attributes->get('center_id');

        $query = DB::table('users_labs_requests as ulr')
            ->join('users as u', 'u.id', '=', 'ulr.user_id')
            ->leftJoin('users_prescriptions as up', 'up.id', '=', 'ulr.user_prescription_id')
            ->where(function ($q) use ($labId) {
                $q->where('ulr.lab_id', $labId)
                    ->orWhereNull('ulr.lab_id');
            })
            ->select(
                'ulr.id as request_id',
                'ulr.visit_type',
                'ulr.status as request_status',
                DB::raw('ulr.total_price / 10 as total_price'), // تبدیل ریال به تومان
                'ulr.created_at as request_date',
                'u.id as user_id',
                'u.name as user_name',
                'u.phone as user_phone',
                'up.prescription_type_id',
                'up.details as prescription_details'
            );

        // اعمال فیلتر نوع مراجعه (حضوری / در منزل)
        if ($request->filled('visit_type')) {
            $query->where('ulr.visit_type', $request->query('visit_type'));
        }

        // اعمال فیلتر وضعیت درخواست (۰ تا ۶)
        if ($request->filled('status')) {
            $query->where('ulr.status', (int) $request->query('status'));
        }

        // فیلتر جستجوی متنی (نام، شماره تماس یا شماره درخواست)
        if ($request->filled('search')) {
            $search = trim($request->query('search'));
            $query->where(function ($q) use ($search) {
                $q->where('u.name', 'LIKE', "%{$search}%")
                    ->orWhere('u.phone', 'LIKE', "%{$search}%")
                    ->orWhere('ulr.id', $search);
            });
        }

        // فیلتر بازه زمانی
        if ($request->filled('from_date')) {
            $query->where('ulr.created_at', '>=', $request->query('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->where('ulr.created_at', '<=', $request->query('to_date'));
        }

        $perPage = (int) $request->query('per_page', 15);
        $paginator = $query->orderByDesc('ulr.id')->paginate($perPage);

        // لود بهینه لیست آزمایش‌های هر درخواست به صورت گروهی (Eager-like Batching)
        $requestIds = collect($paginator->items())->pluck('request_id')->toArray();

        if (!empty($requestIds)) {
            $tests = DB::table('lab_request_test_packs as lrtp')
                ->leftJoin('test_packs as tp', 'tp.id', '=', 'lrtp.lab_test_id')
                ->leftJoin('labs_tests as lt', function ($join) use ($labId) {
                    $join->on('lt.test_pack_id', '=', 'tp.id')
                        ->where('lt.lab_id', '=', $labId);
                })
                ->whereIn('lrtp.lab_request_id', $requestIds)
                ->select(
                    'lrtp.lab_request_id',
                    'tp.id as test_pack_id',
                    'tp.name as test_name',
                    DB::raw('COALESCE(lt.price, 0) / 10 as test_price')
                )
                ->get()
                ->groupBy('lab_request_id');

            foreach ($paginator->items() as $item) {
                if (is_string($item->prescription_details)) {
                    $item->prescription_details = json_decode($item->prescription_details);
                }
                $item->tests = $tests->get($item->request_id, collect())->values()->all();
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $paginator->items(),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * مشاهده جزئیات یک درخواست خاص
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $labId = (int) $request->attributes->get('center_id');

        $item = DB::table('users_labs_requests as ulr')
            ->join('users as u', 'u.id', '=', 'ulr.user_id')
            ->leftJoin('users_prescriptions as up', 'up.id', '=', 'ulr.user_prescription_id')
            ->where('ulr.id', $id)
            ->where(function ($q) use ($labId) {
                $q->where('ulr.lab_id', $labId)
                    ->orWhereNull('ulr.lab_id');
            })
            ->select(
                'ulr.id as request_id',
                'ulr.visit_type',
                'ulr.status as request_status',
                DB::raw('ulr.total_price / 10 as total_price'),
                'ulr.created_at as request_date',
                'u.id as user_id',
                'u.name as user_name',
                'u.phone as user_phone',
                'up.prescription_type_id',
                'up.details as prescription_details'
            )
            ->first();

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'درخواست مورد نظر یافت نشد یا دسترسی به آن ندارید.',
            ], Response::HTTP_NOT_FOUND);
        }

        if (is_string($item->prescription_details)) {
            $item->prescription_details = json_decode($item->prescription_details);
        }

        // واکشی آزمایش‌های انتخاب شده برای این درخواست
        $item->tests = DB::table('lab_request_test_packs as lrtp')
            ->leftJoin('test_packs as tp', 'tp.id', '=', 'lrtp.lab_test_id')
            ->leftJoin('labs_tests as lt', function ($join) use ($labId) {
                $join->on('lt.test_pack_id', '=', 'tp.id')
                    ->where('lt.lab_id', '=', $labId);
            })
            ->where('lrtp.lab_request_id', $id)
            ->select(
                'tp.id as test_pack_id',
                'tp.name as test_name',
                DB::raw('COALESCE(lt.price, 0) / 10 as test_price')
            )
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $item,
        ], Response::HTTP_OK);
    }

    /**
     * تغییر وضعیت درخواست آزمایشگاه
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $labId = (int) $request->attributes->get('center_id');

        $validated = $request->validate([
            'status' => 'required|integer|in:0,1,2,3,4,5,6',
        ], [
            'status.required' => 'ارسال فیلد وضعیت الزامی است.',
            'status.in'       => 'کد وضعیت ارسالی نامعتبر است.',
        ]);

        $labRequest = DB::table('users_labs_requests')
            ->where('id', $id)
            ->where(function ($q) use ($labId) {
                $q->where('lab_id', $labId)
                    ->orWhereNull('lab_id');
            })
            ->first();

        if (!$labRequest) {
            return response()->json([
                'success' => false,
                'message' => 'درخواست آزمایشگاه یافت نشد یا مجاز به تغییر آن نیستید.',
            ], Response::HTTP_NOT_FOUND);
        }

        $updateData = [
            'status'     => (int) $validated['status'],
            'updated_at' => now(),
        ];

        // چنانچه درخواست عمومی بوده (lab_id نال باشد)، با اولین تغییر وضعیت به همین آزمایشگاه اختصاص می‌یابد
        if (is_null($labRequest->lab_id) && $labId) {
            $updateData['lab_id'] = $labId;
        }

        DB::table('users_labs_requests')
            ->where('id', $id)
            ->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'وضعیت درخواست با موفقیت به‌روزرسانی شد.',
            'data'    => [
                'request_id' => $id,
                'new_status' => (int) $validated['status'],
            ],
        ], Response::HTTP_OK);
    }
}
