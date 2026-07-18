<?php
// app/Http/Controllers/Owner/MedicalCenters/MedicalCenterRequestController.php

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
     * لیست درخواست‌ها برای تقویم و زمان‌بندی (تاریخ میلادی)
     */
    public function schedule(Request $request)
    {
        $medicalCenterId = $request->medical_center_id;

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


    private $statusMap = [
        0 => 'new',
        1 => 'accepted',
        2 => 'on_way',
        3 => 'in_progress',
        4 => 'completed',
        5 => 'canceled'
    ];

    /**
     * لیست تمام درخواست‌های مرکز درمانی (هماهنگ با جدول فرانت‌اند)
     */
    public function index(Request $request)
    {
        $medicalCenterId = $request->medical_center_id;

        $page = (int) $request->query('page', 1);
        $pageSize = (int) $request->query('pageSize', 8);
        $sortOrder = $request->query('sortOrder', 'desc');

        // استفاده از نام صحیح جدول و جوین‌های جدید
        $query = DB::table('user_medical_center_requests as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('user_medical_center_request_services as umrs', 'umrs.user_medical_center_request_id', '=', 'r.id')
            ->leftJoin('medical_center_services as mcs', 'mcs.id', '=', 'umrs.medical_center_service_id')
            ->leftJoin('medical_services as ms', 'ms.id', '=', 'mcs.medical_service_id')
            ->where('r.medical_center_id', $medicalCenterId);

        // فیلترهای فرانت‌اند
        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
                $q->where('u.name', 'like', '%' . $request->search . '%')
                    ->orWhere('u.phone', 'like', '%' . $request->search . '%')
                    ->orWhere('r.id', $request->search);
            });
        }

        if ($request->filled('patientName')) {
            $query->where('u.name', 'like', '%' . $request->patientName . '%');
        }

        if ($request->filled('patientPhone')) {
            $query->where('u.phone', 'like', '%' . $request->patientPhone . '%');
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $numericStatus = array_search($request->status, $this->statusMap);
            if ($numericStatus !== false) {
                $query->where('r.status', $numericStatus);
            }
        }

        if ($request->filled('dateFrom')) {
            $query->whereDate('r.created_at', '>=', $request->dateFrom);
        }
        if ($request->filled('dateTo')) {
            $query->whereDate('r.created_at', '<=', $request->dateTo);
        }

        // چون قرار است از GROUP BY استفاده کنیم، گرفتن Count کلی با متد معمول خطا می‌دهد
        // بنابراین ابتدا count را روی id های یکتا محاسبه می‌کنیم
        $total = $query->distinct('r.id')->count('r.id');
        $totalPages = ceil($total / $pageSize);

        // حالا سلکت و گروه‌بندی را اعمال می‌کنیم
        $rows = $query->select(
            'r.id',
            'r.status',
            'r.created_at',
            'r.total_price as amount', // اصلاح نام فیلد مبلغ
            'u.name as user_name',
            'u.phone as user_mobile',
            DB::raw("GROUP_CONCAT(ms.name SEPARATOR '، ') as service_name") // ادغام نام سرویس‌ها
        )
            ->groupBy(
                'r.id',
                'r.status',
                'r.created_at',
                'r.total_price',
                'u.name',
                'u.phone'
            )
            ->orderBy('r.id', $sortOrder)
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $items = $rows->map(function ($row) {
            $date = \Carbon\Carbon::parse($row->created_at);
            return [
                'id'            => $row->id,
                'code'          => 'REQ-' . str_pad($row->id, 4, '0', STR_PAD_LEFT),
                'patientName'   => $row->user_name ?? 'نامشخص',
                'patientPhone'  => $row->user_mobile ?? 'ندارد',
                'serviceType'   => $row->service_name ?? 'خدمات عمومی',
                'scheduledDate' => $date->format('Y/m/d'),
                'scheduledTime' => $date->format('H:i'),
                'amount'        => (float) $row->amount,
                'status'        => $this->statusMap[$row->status] ?? 'new',
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'items'      => $items,
                'total'      => $total,
                'totalPages' => $totalPages > 0 ? $totalPages : 1,
            ]
        ]);
    }


    /**
     * جزئیات یک درخواست (هماهنگ با صفحه جزئیات فرانت)
     */
    public function show(Request $request, int $id)
    {
        $medicalCenterId = $request->medical_center_id;

        $req = DB::table('user_medical_center_requests as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.id', $id)
            ->where('r.medical_center_id', $medicalCenterId)
            ->select(
                'r.*',
                'r.total_price as amount', // هماهنگ با index (اونجا total_price بود، اینجا price بود - باگ دیگه)
                'u.name as user_name',
                'u.phone as user_mobile'
            )
            ->first();

        if (!$req) {
            return $this->error('درخواست یافت نشد', 404);
        }

        // خدمات مرتبط با این درخواست (یک به چند)
        $services = DB::table('user_medical_center_request_services as umrs')
            ->join('medical_center_services as mcs', 'mcs.id', '=', 'umrs.medical_center_service_id')
            ->join('medical_services as ms', 'ms.id', '=', 'mcs.medical_service_id')
            ->where('umrs.user_medical_center_request_id', $req->id)
            ->select('ms.id', 'ms.name', 'mcs.price')
            ->get();

        $date = Carbon::parse($req->created_at);

        $data = [
            'id'            => $req->id,
            'code'          => 'REQ-' . str_pad($req->id, 4, '0', STR_PAD_LEFT),
            'status'        => $this->statusMap[$req->status] ?? 'new',
            'patientName'   => $req->user_name ?? 'نامشخص',
            'patientPhone'  => $req->user_mobile ?? 'ندارد',
            'serviceType'   => $services->pluck('name')->implode('، ') ?: 'خدمات نمونه',
            'services'      => $services->map(fn($s) => [
                'id'    => $s->id,
                'name'  => $s->name,
                'price' => (float) $s->price,
            ]),
            'scheduledDate' => $date->format('Y/m/d'),
            'scheduledTime' => $date->format('H:i'),
            'amount'        => (float) $req->amount,
            'address'       => $req->address ?? 'تهران، خیابان ولیعصر، پلاک نمونه',
            'note'          => $req->note ?? 'بیمار دارای سابقه فشار خون است.',
            'extra_info'    => is_string($req->extra_info) ? json_decode($req->extra_info, true) : $req->extra_info,

            'timeline'      => [
                [
                    'label'  => 'ثبت درخواست',
                    'date'   => $date->format('Y/m/d H:i'),
                    'status' => 'done'
                ],
                [
                    'label'  => 'تایید مرکز',
                    'date'   => $date->copy()->addHours(1)->format('Y/m/d H:i'),
                    'status' => $req->status >= 1 ? 'done' : 'pending'
                ]
            ],
        ];

        return response()->json(['success' => true, 'data' => $data]);
    }




    /**
     * بروزرسانی وضعیت درخواست
     */
    public function updateStatus(Request $request, int $id)
    {
        $medicalCenterId = $request->medical_center_id;

        $exists = DB::table('users_medical_center_requests')
            ->where('id', $id)
            ->where('medical_center_id', $medicalCenterId)
            ->exists();

        if (!$exists) {
            return $this->error('درخواست یافت نشد', 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->error('داده‌های ورودی نامعتبر است', 422, $validator->errors());
        }

        DB::table('users_medical_center_requests')
            ->where('id', $id)
            ->where('medical_center_id', $medicalCenterId)
            ->update(['status' => $request->input('status')]);

        return $this->success(null, 'وضعیت درخواست بروزرسانی شد');
    }

    /**
     * آمار کلی درخواست‌ها
     */
    public function stats(Request $request)
    {
        $medicalCenterId = $request->medical_center_id;

        $total = DB::table('users_medical_center_requests')->where('medical_center_id', $medicalCenterId)->count();
        $pending = DB::table('users_medical_center_requests')->where('medical_center_id', $medicalCenterId)->where('status', 0)->count();
        $confirmed = DB::table('users_medical_center_requests')->where('medical_center_id', $medicalCenterId)->where('status', 1)->count();
        $rejected = DB::table('users_medical_center_requests')->where('medical_center_id', $medicalCenterId)->where('status', 2)->count();

        return $this->success([
            'total'     => $total,
            'pending'   => $pending,
            'confirmed' => $confirmed,
            'rejected'  => $rejected,
        ]);
    }
}
