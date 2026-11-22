<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class BeautyClinicRequestController extends Controller
{
    /**
     * ۱. لیست درخواست‌های کلینیک زیبایی
     * شامل درخواست‌های اختصاص‌یافته به این کلینیک + درخواست‌های عمومی (مشاوره/تصویر بدون کلینیک)
     * GET /api/owner/beauty-clinic/requests
     */
    public function index(Request $request)
    {
        $clinicId = $request->user()->id;

        $requests = DB::table('users_beauty_requests as ubr')
            ->join('users as u', 'u.id', '=', 'ubr.user_id')
            ->leftJoin('users_prescriptions as up', 'up.id', '=', 'ubr.user_prescription_id')
            ->leftJoin('beauty_clinic_slots as bcs', 'bcs.id', '=', 'ubr.slot_id')
            ->where(function ($q) use ($clinicId) {
                $q->where('ubr.clinic_id', $clinicId)
                    ->orWhere(function ($q2) {
                        $q2->whereNull('ubr.clinic_id')
                            ->where('ubr.status', 0);
                    });
            })
            ->orderByDesc('ubr.id')
            ->select(
                'ubr.id as request_id',
                'ubr.visit_type',
                'ubr.status as request_status',
                DB::raw('ubr.total_price / 10 as total_price'),
                'ubr.created_at as request_date',
                'ubr.appointment_date',
                DB::raw("IF(bcs.id IS NOT NULL, CONCAT(LEFT(bcs.start_time, 5), ' تا ', LEFT(bcs.end_time, 5)), NULL) as slot_time"),
                'u.id as user_id',
                'u.name as user_name',
                'u.phone as user_phone',
                'up.prescription_type_id',
                'up.details as prescription_details'
            )
            ->get();

        if ($requests->isEmpty()) {
            return response()->json(['status' => true, 'data' => []]);
        }

        // دریافت خدمات تمام درخواست‌ها در یک کوئری (جلوگیری از N+1)
        $requestIds = $requests->pluck('request_id')->toArray();

        $services = DB::table('beauty_request_service_packs as brsp')
            ->join('beauty_clinic_services as bcs', 'bcs.id', '=', 'brsp.clinic_service_id')
            ->join('beauty_service_packs as bsp', 'bsp.id', '=', 'bcs.service_pack_id')
            ->whereIn('brsp.beauty_request_id', $requestIds)
            ->select(
                'brsp.beauty_request_id',
                'bsp.id as service_pack_id',
                'bsp.name as service_name',
                DB::raw('bcs.price / 10 as service_price')
            )
            ->get()
            ->groupBy('beauty_request_id');

        $formatted = $requests->map(function ($item) use ($services) {
            $item->prescription_details = $item->prescription_details
                ? json_decode($item->prescription_details, true)
                : ['code' => '', 'files' => []];
            $item->services = $services->get($item->request_id, collect())->values();
            return $item;
        });

        return response()->json([
            'status' => true,
            'data'   => $formatted,
        ]);
    }

    /**
     * ۲. جزئیات یک درخواست کلینیک زیبایی
     * GET /api/owner/beauty-clinic/requests/{id}
     */
    public function show(Request $request, $id)
    {
        $clinicId = $request->user()->id;

        $item = DB::table('users_beauty_requests as ubr')
            ->join('users as u', 'u.id', '=', 'ubr.user_id')
            ->leftJoin('addresses as addr', 'addr.id', '=', 'ubr.address_id')
            ->leftJoin('users_prescriptions as up', 'up.id', '=', 'ubr.user_prescription_id')
            ->leftJoin('beauty_clinic_slots as bcs', 'bcs.id', '=', 'ubr.slot_id')
            ->where('ubr.id', $id)
            ->where(function ($q) use ($clinicId) {
                $q->where('ubr.clinic_id', $clinicId)
                    ->orWhereNull('ubr.clinic_id');
            })
            ->select(
                'ubr.*',
                'u.name as patient_name',
                'u.phone as patient_phone',
                'addr.address as user_address',
                'up.id as prescription_id',
                'up.prescription_type_id',
                'up.details as prescription_details',
                DB::raw("IF(bcs.id IS NOT NULL, CONCAT(LEFT(bcs.start_time, 5), ' تا ', LEFT(bcs.end_time, 5)), NULL) as slot_time")
            )
            ->first();

        if (!$item) {
            return response()->json([
                'status'  => false,
                'message' => 'درخواست مورد نظر یافت نشد.',
            ], 404);
        }

        // پردازش جزئیات نسخه / ضمائم
        $prescriptionDetails = $item->prescription_details
            ? json_decode($item->prescription_details, true)
            : ['code' => '', 'files' => []];

        $secureFiles = [];
        if (!empty($prescriptionDetails['files']) && is_array($prescriptionDetails['files'])) {
            foreach ($prescriptionDetails['files'] as $filePath) {
                $fileName = basename($filePath);
                $secureFiles[] = "https://api.mediraai.com/api/owner/beauty-clinic/prescription-file/{$item->prescription_id}/{$fileName}";
            }
        }

        $prescriptionType = 'none';
        if ((int) $item->request_type_id === 2) {
            $prescriptionType = 'digital';
        } elseif ((int) $item->request_type_id === 3) {
            $prescriptionType = 'file';
        }

        // دریافت لیست خدمات انتخاب‌شده
        $services = DB::table('beauty_request_service_packs as brsp')
            ->join('beauty_clinic_services as bcs', 'bcs.id', '=', 'brsp.clinic_service_id')
            ->join('beauty_service_packs as bsp', 'bsp.id', '=', 'bcs.service_pack_id')
            ->where('brsp.beauty_request_id', $item->id)
            ->select(
                'bsp.id as service_pack_id',
                'bsp.name',
                DB::raw('bcs.price / 10 as price')
            )
            ->get();

        return response()->json([
            'status' => true,
            'data'   => [
                'id'                => $item->id,
                'code'              => "BC-{$item->id}",
                'is_assigned'       => (int) $item->clinic_id === (int) $clinicId,
                'status'            => (int) $item->status,
                'scheduledDate'     => $item->created_at,
                'appointment_date'  => $item->appointment_date,
                'slot_time'         => $item->slot_time,
                'patientName'       => $item->patient_name ?? 'نامشخص',
                'patientPhone'      => $item->patient_phone ?? '-',
                'address'           => $item->user_address ?? null,
                'prescriptionType'  => $prescriptionType,
                'prescriptionCode'  => $prescriptionDetails['code'] ?? '',
                'prescriptionFiles' => $secureFiles,
                'services'          => $services,
                'totalPrice'        => (float) $item->total_price / 10,
            ],
        ]);
    }

    /**
     * ۳. پذیرش درخواست عمومی (حالت مشاوره یا آپلود تصویر) توسط کلینیک
     * POST /api/owner/beauty-clinic/requests/{id}/accept
     */
    public function accept(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'appointment_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        $clinicId = $request->user()->id;

        $updated = DB::table('users_beauty_requests')
            ->where('id', $id)
            ->whereNull('clinic_id')
            ->where('status', 0)
            ->update([
                'clinic_id'        => $clinicId,
                'appointment_date' => $request->input('appointment_date'),
                'updated_at'       => now(),
            ]);

        if (!$updated) {
            return response()->json([
                'status'  => false,
                'message' => 'این درخواست قبلاً توسط کلینیک دیگری پذیرفته شده یا قابل پذیرش نیست.',
            ], 409);
        }

        return response()->json([
            'status'  => true,
            'message' => 'درخواست با موفقیت پذیرفته شد.',
        ]);
    }

    /**
     * ۴. بروزرسانی تاریخ مراجعه
     * PUT /api/owner/beauty-clinic/requests/{id}/schedule
     */
    public function updateSchedule(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'appointment_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        $clinicId = $request->user()->id;

        $updated = DB::table('users_beauty_requests')
            ->where('id', $id)
            ->where('clinic_id', $clinicId)
            ->where('status', '<=', 2)
            ->update([
                'appointment_date' => $request->input('appointment_date'),
                'updated_at'       => now(),
            ]);

        if (!$updated) {
            return response()->json([
                'status'  => false,
                'message' => 'امکان تغییر زمان‌بندی این درخواست وجود ندارد.',
            ], 400);
        }

        return response()->json([
            'status'  => true,
            'message' => 'زمان‌بندی با موفقیت بروزرسانی شد.',
        ]);
    }

    /**
     * ۵. تغییر وضعیت درخواست
     * PUT /api/owner/beauty-clinic/requests/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|integer|in:0,1,2,3,4,5,6',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        $clinicId  = $request->user()->id;
        $newStatus = (int) $request->input('status');

        $req = DB::table('users_beauty_requests')
            ->where('id', $id)
            ->where('clinic_id', $clinicId)
            ->first();

        if (!$req) {
            return response()->json(['status' => false, 'message' => 'درخواست یافت نشد.'], 404);
        }

        // آزادسازی ظرفیت اسلات در صورت لغو شدن درخواست
        DB::transaction(function () use ($req, $newStatus) {
            DB::table('users_beauty_requests')
                ->where('id', $req->id)
                ->update([
                    'status'     => $newStatus,
                    'updated_at' => now(),
                ]);

            if ($newStatus === 6 && (int) $req->status !== 6 && !empty($req->slot_id)) {
                DB::table('beauty_clinic_slots')
                    ->where('id', $req->slot_id)
                    ->where('booked_count', '>', 0)
                    ->decrement('booked_count');
            }
        });

        return response()->json([
            'status'  => true,
            'message' => 'وضعیت درخواست با موفقیت تغییر کرد.',
        ]);
    }

    /**
     * ۶. لیست خدمات فعال تعریف‌شده برای این کلینیک
     * GET /api/owner/beauty-clinic/services/available
     */
    public function availableServices(Request $request)
    {
        $clinicId = $request->user()->id;

        $services = DB::table('beauty_clinic_services as bcs')
            ->join('beauty_service_packs as bsp', 'bsp.id', '=', 'bcs.service_pack_id')
            ->where('bcs.clinic_id', $clinicId)
            ->where('bcs.status', 1)
            ->where('bsp.status', 1)
            ->select(
                'bcs.id as clinic_service_id',
                'bsp.name',
                DB::raw('bcs.price / 10 as price')
            )
            ->orderBy('bsp.name')
            ->get();

        return response()->json([
            'status' => true,
            'data'   => $services,
        ]);
    }

    /**
     * ۷. تخصیص خدمات زیبایی به درخواست و محاسبه مبلغ کل
     * POST /api/owner/beauty-clinic/requests/{id}/assign-services
     */
    public function assignServices(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'clinic_service_ids'   => 'required|array|min:1',
            'clinic_service_ids.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        $clinicId   = $request->user()->id;
        $serviceIds = array_values(array_unique($request->input('clinic_service_ids')));

        $req = DB::table('users_beauty_requests')
            ->where('id', $id)
            ->where('clinic_id', $clinicId)
            ->whereIn('status', [0, 1])
            ->first();

        if (!$req) {
            return response()->json([
                'status'  => false,
                'message' => 'درخواست یافت نشد یا امکان ویرایش خدمات آن وجود ندارد.',
            ], 404);
        }

        $services = DB::table('beauty_clinic_services')
            ->where('clinic_id', $clinicId)
            ->where('status', 1)
            ->whereIn('id', $serviceIds)
            ->get();

        if ($services->count() !== count($serviceIds)) {
            return response()->json([
                'status'  => false,
                'message' => 'برخی خدمات انتخابی معتبر نیستند.',
            ], 422);
        }

        DB::transaction(function () use ($id, $services) {
            DB::table('beauty_request_service_packs')
                ->where('beauty_request_id', $id)
                ->delete();

            $rows = [];
            $totalPrice = 0;

            foreach ($services as $srv) {
                $totalPrice += $srv->price;
                $rows[] = [
                    'beauty_request_id' => $id,
                    'clinic_service_id' => $srv->id,
                    'status'            => 0,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ];
            }

            DB::table('beauty_request_service_packs')->insert($rows);

            DB::table('users_beauty_requests')
                ->where('id', $id)
                ->update([
                    'total_price' => $totalPrice,
                    'status'      => 1, // در انتظار پرداخت
                    'updated_at'  => now(),
                ]);
        });

        return response()->json([
            'status'  => true,
            'message' => 'خدمات با موفقیت به درخواست تخصیص داده شدند.',
        ]);
    }

    /**
     * ۸. حذف لیست خدمات تخصیص‌داده‌شده (جهت انتخاب مجدد قبل از پرداخت)
     * DELETE /api/owner/beauty-clinic/requests/{id}/assign-services
     */
    public function unassignServices(Request $request, $id)
    {
        $clinicId = $request->user()->id;

        $req = DB::table('users_beauty_requests')
            ->where('id', $id)
            ->where('clinic_id', $clinicId)
            ->whereIn('status', [0, 1])
            ->first();

        if (!$req) {
            return response()->json([
                'status'  => false,
                'message' => 'درخواست یافت نشد یا پس از پرداخت امکان حذف خدمات وجود ندارد.',
            ], 400);
        }

        DB::transaction(function () use ($id) {
            DB::table('beauty_request_service_packs')
                ->where('beauty_request_id', $id)
                ->delete();

            DB::table('users_beauty_requests')
                ->where('id', $id)
                ->update([
                    'total_price' => 0,
                    'status'      => 0,
                    'updated_at'  => now(),
                ]);
        });

        return response()->json([
            'status'  => true,
            'message' => 'لیست خدمات حذف شد.',
        ]);
    }

    /**
     * ۹. دانلود امن فایل ضمیمه مشاوره
     * GET /api/owner/beauty-clinic/prescription-file/{prescriptionId}/{fileName}
     */
    public function downloadFile(Request $request, $prescriptionId, $fileName)
    {
        $clinicId = $request->user()->id;

        $hasAccess = DB::table('users_beauty_requests')
            ->where('user_prescription_id', $prescriptionId)
            ->where(function ($q) use ($clinicId) {
                $q->where('clinic_id', $clinicId)
                    ->orWhereNull('clinic_id');
            })
            ->exists();

        if (!$hasAccess) {
            return response()->json(['status' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
        }

        $targetPath = 'prescriptions/' . basename($fileName);

        if (!Storage::disk('local')->exists($targetPath)) {
            return response()->json(['status' => false, 'message' => 'فایل یافت نشد.'], 404);
        }

        $mimeType = Storage::disk('local')->mimeType($targetPath);
        return Storage::disk('local')->response($targetPath, basename($fileName), [
            'Content-Type' => $mimeType,
        ]);
    }
}
