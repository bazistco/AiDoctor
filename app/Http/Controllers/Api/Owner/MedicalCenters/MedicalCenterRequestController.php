<?php

namespace App\Http\Controllers\Api\Owner\MedicalCenters;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MedicalCenterRequestController extends Controller
{
    use ApiResponse;

    /**
     * تبدیل وضعیت عددی به متن برای پرستاری
     */
    private function getNurseStatus($status): string
    {
        return match ((int) $status) {
            0 => 'pending_payment',    // در انتظار پرداخت
            1 => 'pending_nurse',      // در انتظار انتخاب پرستار
            2 => 'pending_visit',      // در انتظار مراجعه پرستار
            3 => 'visited',            // مراجعه شده
            4 => 'completed',          // تکمیل شده
            5 => 'canceled',           // لغو شده
            default => 'pending_payment'
        };
    }

    /**
     * تبدیل وضعیت متن به عدد برای پرستاری
     */
    private function getNumericStatus(string $status): int
    {
        return match ($status) {
            'pending_payment' => 0,
            'pending_nurse'   => 1,
            'pending_visit'   => 2,
            'visited'         => 3,
            'completed'       => 4,
            'canceled'        => 5,
            default           => 0
        };
    }

    /**
     * لیست درخواست‌ها با فیلتر و صفحه‌بندی
     */
    public function index(Request $request)
    {
        $medicalCenterId = auth()->user()->id;

        $page = (int) $request->query('page', 1);
        $pageSize = (int) $request->query('pageSize', 8);
        $sortOrder = $request->query('sortOrder', 'desc');
        $search = $request->query('search', '');
        $status = $request->query('status', 'all');
        $patientName = $request->query('patientName', '');
        $patientPhone = $request->query('patientPhone', '');
        $dateFrom = $request->query('dateFrom', '');
        $dateTo = $request->query('dateTo', '');

        $query = DB::table('user_medical_center_requests as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('medical_center_staffs as s', 's.id', '=', 'r.staff_id')
            ->where('r.medical_center_id', $medicalCenterId);

        // فیلترها
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('u.name', 'like', '%' . $search . '%')
                    ->orWhere('u.phone', 'like', '%' . $search . '%')
                    ->orWhere('r.id', $search);
            });
        }

        if ($patientName) {
            $query->where('u.name', 'like', '%' . $patientName . '%');
        }

        if ($patientPhone) {
            $query->where('u.phone', 'like', '%' . $patientPhone . '%');
        }

        if ($status && $status !== 'all') {
            $numericStatus = $this->getNumericStatus($status);
            $query->where('r.status', $numericStatus);
        }

        if ($dateFrom) {
            $query->whereDate('r.start_time', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('r.start_time', '<=', $dateTo);
        }

        // شمارش کل
        $total = $query->count();
        $totalPages = ceil($total / $pageSize);

        // دریافت داده‌ها
        $rows = $query->select(
            'r.id',
            'r.status',
            'r.start_time',
            'r.end_time',
            'r.total_price as amount',
            'u.name as user_name',
            'u.phone as user_mobile',
            's.name as staff_name'
        )
            ->orderBy('r.start_time', $sortOrder)
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $items = $rows->map(function ($row) {
            $startDate = Carbon::parse($row->start_time);
            $endDate = $row->end_time ? Carbon::parse($row->end_time) : null;

            // محاسبه مدت زمان
            $duration = $endDate ? $startDate->diffInMinutes($endDate) : 60;

            return [
                'id'            => $row->id,
                'code'          => 'NR-' . str_pad($row->id, 4, '0', STR_PAD_LEFT),
                'patientName'   => $row->user_name ?? 'نامشخص',
                'patientPhone'  => $row->user_mobile ?? 'ندارد',
                'scheduledDate' => $startDate->format('Y/m/d'),
                'scheduledTime' => $startDate->format('H:i'),
                'duration'      => $duration,
                'amount'        => (float) $row->amount,
                'status'        => $this->getNurseStatus($row->status),
                'staffName'     => $row->staff_name,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'items'      => $items,
                'total'      => $total,
                'totalPages' => $totalPages > 0 ? $totalPages : 1,
                'page'       => $page,
                'pageSize'   => $pageSize,
            ]
        ]);
    }

    /**
     * جزئیات درخواست
     */
    public function show(Request $request, int $id)
    {
        $medicalCenterId = auth()->user()->id;

        $req = DB::table('user_medical_center_requests as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('medical_center_staffs as s', 's.id', '=', 'r.staff_id')
            ->where('r.id', $id)
            ->where('r.medical_center_id', $medicalCenterId)
            ->select(
                'r.*',
                'u.name as user_name',
                'u.phone as user_mobile',
                's.name as staff_name',
                's.mobile as staff_mobile'
            )
            ->first();

        if (!$req) {
            return $this->error('درخواست یافت نشد', 404);
        }

        $startDate = Carbon::parse($req->start_time);
        $endDate = $req->end_time ? Carbon::parse($req->end_time) : null;
        $duration = $endDate ? $startDate->diffInMinutes($endDate) : 60;

        // extra_info را پردازش کنیم
        $extraInfo = is_string($req->extra_info) ? json_decode($req->extra_info, true) : $req->extra_info;

        // timeline بر اساس وضعیت‌ها
        $timeline = [];
        $timeline[] = [
            'label'  => 'ثبت درخواست',
            'date'   => $startDate->format('Y/m/d H:i'),
            'status' => 'done'
        ];

        if ($req->status >= 1) {
            $timeline[] = [
                'label'  => 'تایید مرکز درمانی',
                'date'   => $startDate->copy()->addHours(1)->format('Y/m/d H:i'),
                'status' => 'done'
            ];
        }

        if ($req->status >= 2 && $req->staff_id) {
            $timeline[] = [
                'label'  => 'اختصاص پرستار',
                'date'   => $startDate->copy()->addHours(2)->format('Y/m/d H:i'),
                'status' => 'done'
            ];
        }

        if ($req->status >= 3) {
            $timeline[] = [
                'label'  => 'مراجعه پرستار',
                'date'   => $startDate->copy()->addHours(3)->format('Y/m/d H:i'),
                'status' => 'done'
            ];
        }

        if ($req->status >= 4) {
            $timeline[] = [
                'label'  => 'تکمیل خدمات',
                'date'   => $endDate ? $endDate->format('Y/m/d H:i') : $startDate->copy()->addHours(4)->format('Y/m/d H:i'),
                'status' => 'done'
            ];
        }

        $data = [
            'id'            => $req->id,
            'code'          => 'NR-' . str_pad($req->id, 4, '0', STR_PAD_LEFT),
            'status'        => $this->getNurseStatus($req->status),
            'patientName'   => $req->user_name ?? 'نامشخص',
            'patientPhone'  => $req->user_mobile ?? 'ندارد',
            'serviceType'   => 'خدمات پرستاری',
            'requestType'   => 'nurse',
            'scheduledDate' => $startDate->format('Y/m/d'),
            'scheduledTime' => $startDate->format('H:i'),
            'duration'      => $duration,
            'amount'        => (float) $req->total_price,
            'address'       => $req->address ?? 'ثبت نشده',
            'note'          => $req->note ?? '',
            'extra_info'    => $extraInfo,

            // اطلاعات پرستار اختصاص داده شده
            'assignedStaff' => $req->staff_id ? [
                'id' => $req->staff_id,
                'name' => $req->staff_name,
                'mobile' => $req->staff_mobile,
            ] : null,

            'timeline'      => $timeline,
        ];

        return $this->success($data);
    }

    /**
     * تغییر وضعیت درخواست
     */
    public function updateStatus(Request $request, int $id)
    {
        $medicalCenterId = auth()->user()->id;

        $req = DB::table('user_medical_center_requests')
            ->where('id', $id)
            ->where('medical_center_id', $medicalCenterId)
            ->first();

        if (!$req) {
            return $this->error('درخواست یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:pending_nurse,pending_visit,visited,completed,canceled',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        $numericStatus = $this->getNumericStatus($request->status);

        // بررسی valid transition
        $currentStatus = $this->getNurseStatus($req->status);
        $allowedTransitions = $this->getAllowedTransitions($currentStatus);

        if (!in_array($request->status, $allowedTransitions)) {
            return $this->error('تغییر وضعیت مجاز نیست', 400);
        }

        DB::table('user_medical_center_requests')
            ->where('id', $id)
            ->update([
                'status' => $numericStatus,
                'updated_at' => now()
            ]);

        return $this->success(null, 'وضعیت درخواست بروزرسانی شد');
    }

    /**
     * اختصاص پرسنل به درخواست
     */
    public function assignStaff(Request $request, int $id)
    {
        $medicalCenterId = auth()->user()->id;

        $req = DB::table('user_medical_center_requests')
            ->where('id', $id)
            ->where('medical_center_id', $medicalCenterId)
            ->first();

        if (!$req) {
            return $this->error('درخواست یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'staff_id' => 'required|integer|exists:medical_center_staffs,id',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        // بررسی اینکه پرسنل متعلق به همین مرکز باشد
        $staff = DB::table('medical_center_staffs')
            ->where('id', $request->staff_id)
            ->where('medical_center_id', $medicalCenterId)
            ->first();

        if (!$staff) {
            return $this->error('پرسنل یافت نشد', 404);
        }

        // اختصاص پرسنل و تغییر وضعیت به pending_visit
        DB::table('user_medical_center_requests')
            ->where('id', $id)
            ->update([
                'staff_id' => $request->staff_id,
                'status' => 2, // pending_visit
                'updated_at' => now()
            ]);

        return $this->success(null, 'پرسنل با موفقیت اختصاص داده شد');
    }

    /**
     * ثبت گزارش برای درخواست
     */
    public function submitReport(Request $request, int $id)
    {
        $medicalCenterId = auth()->user()->id;

        $req = DB::table('user_medical_center_requests')
            ->where('id', $id)
            ->where('medical_center_id', $medicalCenterId)
            ->where('status', 3) // visited
            ->first();

        if (!$req) {
            return $this->error('درخواست یافت نشد یا وضعیت نامناسب دارد', 404);
        }

        $validator = Validator::make($request->all(), [
            'duration_minutes' => 'required|integer|min:1',
            'services_performed' => 'required|string',
            'patient_condition' => 'required|string',
            'recommendations' => 'nullable|string',
            'needs_followup' => 'boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های گزارش نامعتبر است', 422, $validator->errors());
        }

        // ذخیره گزارش در extra_info
        $extraInfo = is_string($req->extra_info) ? json_decode($req->extra_info, true) : $req->extra_info;
        $extraInfo = $extraInfo ?: [];

        $extraInfo['report'] = [
            'duration_minutes' => $request->duration_minutes,
            'services_performed' => $request->services_performed,
            'patient_condition' => $request->patient_condition,
            'recommendations' => $request->recommendations,
            'needs_followup' => $request->needs_followup ?? false,
            'submitted_at' => now()->toDateTimeString(),
            'submitted_by' => auth()->user()->id,
        ];

        // تغییر وضعیت به completed و ذخیره گزارش
        DB::table('user_medical_center_requests')
            ->where('id', $id)
            ->update([
                'status' => 4, // completed
                'extra_info' => json_encode($extraInfo),
                'end_time' => now(),
                'updated_at' => now()
            ]);

        return $this->success(null, 'گزارش با موفقیت ثبت شد');
    }

    /**
     * برنامه‌ریزی درخواست‌ها (برای نمایش در تقویم)
     */
    public function schedule(Request $request)
    {
        $medicalCenterId = auth()->user()->id;

        $rows = DB::table('user_medical_center_requests as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('user_medical_center_request_services as umrs', 'umrs.user_medical_center_request_id', '=', 'r.id')
            ->leftJoin('medical_center_services as mcs', 'mcs.id', '=', 'umrs.medical_center_service_id')
            ->leftJoin('medical_services as ms', 'ms.id', '=', 'mcs.medical_service_id')
            ->where('r.medical_center_id', $medicalCenterId)
            ->select(
                'r.id',
                'r.status',
                'r.created_at as visit_date',
                'u.name as user_name',
                'ms.name as service_name'
            )
            ->orderBy('r.id')
            ->get();

        if ($rows->isEmpty()) {
            return $this->success([]);
        }

        $mappedData = $rows->groupBy('id')->map(function ($items) {
            $first = $items->first();
            $gregorianDate = \Carbon\Carbon::parse($first->visit_date)->format('Y-m-d');

            $services = $items->pluck('service_name')
                ->filter()
                ->unique()
                ->values()
                ->map(fn ($name) => ['name' => $name])
                ->all();

            return [
                'id'            => $first->id,
                'patientName'   => $first->user_name ?? 'بدون نام',
                'code'          => $first->id,
                'status'        => (int) $first->status,
                'scheduledDate' => $gregorianDate,
                'services'      => $services,
            ];
        })->values();

        return $this->success($mappedData);
    }

    /**
     * آمار درخواست‌ها
     */
    public function stats(Request $request)
    {
        $medicalCenterId = auth()->user()->id;

        $total = DB::table('user_medical_center_requests')
            ->where('medical_center_id', $medicalCenterId)
            ->count();

        $pendingNurse = DB::table('user_medical_center_requests')
            ->where('medical_center_id', $medicalCenterId)
            ->where('status', 1) // pending_nurse
            ->count();

        $pendingVisit = DB::table('user_medical_center_requests')
            ->where('medical_center_id', $medicalCenterId)
            ->where('status', 2) // pending_visit
            ->count();

        $completed = DB::table('user_medical_center_requests')
            ->where('medical_center_id', $medicalCenterId)
            ->where('status', 4) // completed
            ->count();

        $canceled = DB::table('user_medical_center_requests')
            ->where('medical_center_id', $medicalCenterId)
            ->where('status', 5) // canceled
            ->count();

        return $this->success([
            'total'         => $total,
            'pending_nurse' => $pendingNurse,
            'pending_visit' => $pendingVisit,
            'completed'     => $completed,
            'canceled'      => $canceled,
        ]);
    }

    /**
     * درخواست‌های اختصاص یافته به یک پرسنل
     */
    public function staffAssignedRequests(Request $request, int $staffId)
    {
        $medicalCenterId = auth()->user()->id;

        // بررسی اینکه پرسنل متعلق به همین مرکز باشد
        $staff = DB::table('medical_center_staffs')
            ->where('id', $staffId)
            ->where('medical_center_id', $medicalCenterId)
            ->first();

        if (!$staff) {
            return $this->error('پرسنل یافت نشد', 404);
        }

        $page = (int) $request->query('page', 1);
        $pageSize = (int) $request->query('pageSize', 10);

        $query = DB::table('user_medical_center_requests as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.medical_center_id', $medicalCenterId)
            ->where('r.staff_id', $staffId);

        $total = $query->count();
        $totalPages = ceil($total / $pageSize);

        $rows = $query->select(
            'r.id',
            'r.status',
            'r.start_time',
            'r.end_time',
            'r.total_price',
            'u.name as patient_name',
            'u.phone as patient_phone'
        )
            ->orderBy('r.start_time', 'desc')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $items = $rows->map(function ($row) {
            $startDate = Carbon::parse($row->start_time);

            return [
                'id' => $row->id,
                'code' => 'NR-' . str_pad($row->id, 4, '0', STR_PAD_LEFT),
                'patientName' => $row->patient_name,
                'patientPhone' => $row->patient_phone,
                'requestType' => 'nurse',
                'scheduledDate' => $startDate->format('Y/m/d'),
                'scheduledTime' => $startDate->format('H:i'),
                'amount' => (float) $row->total_price,
                'status' => $this->getNurseStatus($row->status),
            ];
        });

        return $this->success([
            'items' => $items,
            'total' => $total,
            'totalPages' => $totalPages,
            'page' => $page,
            'pageSize' => $pageSize,
        ]);
    }

    /**
     * مجاز بودن transition وضعیت‌ها
     */
    private function getAllowedTransitions(string $currentStatus): array
    {
        $transitions = [
            'pending_payment' => ['pending_nurse', 'canceled'],
            'pending_nurse'   => ['pending_visit', 'canceled'],
            'pending_visit'   => ['visited', 'canceled'],
            'visited'         => ['completed'],
            'completed'       => [],
            'canceled'        => [],
        ];

        return $transitions[$currentStatus] ?? [];
    }
}
