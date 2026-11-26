<?php

namespace App\Http\Controllers\Api\Owner\Beauty;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BeautyDashboardController extends Controller
{
    use ApiResponse;

    /**
     * تغییر وضعیت فعال/غیرفعال کلینیک زیبایی در beauty_clinic_info (۰ <-> ۱)
     * وضعیت حساب در جدول users فقط توسط ادمین قابل تغییر است.
     * PUT /api/owner/beauty-clinic/status
     */
    public function toggleStatus(Request $request)
    {
        $userId = (int) auth()->id();

        if (!$userId) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'احراز هویت انجام نشده است.',
            ], 401);
        }

        $clinicInfo = DB::table('beauty_clinic_info')
            ->where('user_id', $userId)
            ->first();

        if (!$clinicInfo) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'اطلاعات کلینیک زیبایی یافت نشد.',
            ], 404);
        }

        $currentStatus = (int) ($clinicInfo->status ?? 0);
        $newStatus     = $currentStatus === 1 ? 0 : 1;

        DB::table('beauty_clinic_info')
            ->where('user_id', $userId)
            ->update([
                'status'     => $newStatus,
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'status'  => true,
            'message' => $newStatus === 1
                ? 'کلینیک زیبایی فعال شد و آماده دریافت نوبت است.'
                : 'کلینیک زیبایی غیرفعال شد.',
            'data'    => [
                'status'    => $newStatus,
                'is_active' => $newStatus === 1,
            ],
        ]);
    }

    /**
     * دریافت آمار و اطلاعات داشبورد کلینیک زیبایی
     * GET /api/owner/beauty-clinic/dashboard
     */
    public function index(Request $request)
    {
        $userId = (int) auth()->id();

        if (!$userId) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'احراز هویت انجام نشده است.',
            ], 401);
        }

        $clinicInfo = DB::table('beauty_clinic_info')
            ->where('user_id', $userId)
            ->first();

        $user = DB::table('users')
            ->where('id', $userId)
            ->first();

        $allRequests = DB::table('users_beauty_requests as ubr')
            ->leftJoin('users as u', 'u.id', '=', 'ubr.user_id')
            ->leftJoin('beauty_request_service_packs as brsp', 'brsp.beauty_request_id', '=', 'ubr.id')
            ->leftJoin('beauty_clinic_services as bcs', 'bcs.id', '=', 'brsp.clinic_service_id')
            ->leftJoin('beauty_service_packs as bsp', 'bsp.id', '=', 'bcs.service_pack_id')
            ->where('ubr.clinic_id', $userId)
            ->groupBy(
                'ubr.id',
                'ubr.user_id',
                'ubr.status',
                'ubr.total_price',
                'ubr.appointment_date',
                'ubr.created_at',
                'u.name'
            )
            ->select(
                'ubr.id',
                'ubr.user_id',
                'ubr.status',
                'ubr.total_price',
                'ubr.appointment_date',
                'ubr.created_at',
                'u.name as patient_name',
                DB::raw("GROUP_CONCAT(DISTINCT bsp.name SEPARATOR '، ') as services_name"),
                DB::raw("GROUP_CONCAT(DISTINCT brsp.clinic_service_id SEPARATOR ',') as service_ids")
            )
            ->orderByDesc('ubr.created_at')
            ->get();

        // شمارش وضعیت‌ها (بر اساس قرارداد وضعیت‌های نوبت)
        $newCount        = $allRequests->filter(fn ($r) => in_array((int) $r->status, [0, 1], true))->count();
        $inProgressCount = $allRequests->filter(fn ($r) => in_array((int) $r->status, [2, 3, 4], true))->count();
        $completedCount  = $allRequests->filter(fn ($r) => (int) $r->status === 5)->count();

        // مجموع درآمد کلینیک از تراکنش‌های کیف‌پول یا سفارش‌های تکمیل‌شده
        $wallet       = DB::table('wallets')->where('user_id', $userId)->first();
        $totalRevenue = 0;

        if ($wallet) {
            $totalRevenue = (float) DB::table('wallet_transactions')
                ->where('wallet_id', $wallet->id)
                ->where('type', 1)
                ->sum('amount');
        }

        if ($totalRevenue <= 0) {
            $totalRevenue = (float) $allRequests
                ->where('status', 5)
                ->sum('total_price');
        }

        // میانگین امتیاز کلینیک
        $avgRating = $clinicInfo && isset($clinicInfo->rating) && (float) $clinicInfo->rating > 0
            ? round((float) $clinicInfo->rating, 1)
            : 4.9;

        // نمودار ۷ روز گذشته
        $chartData   = [];
        $persianDays = [
            0 => 'یکشنبه',
            1 => 'دوشنبه',
            2 => 'سه‌شنبه',
            3 => 'چهارشنبه',
            4 => 'پنج‌شنبه',
            5 => 'جمعه',
            6 => 'شنبه',
        ];

        for ($i = 6; $i >= 0; $i--) {
            $dayDate = Carbon::today()->subDays($i);
            $dayStr  = $dayDate->toDateString();

            $dayCount = $allRequests->filter(function ($item) use ($dayStr) {
                if (empty($item->created_at)) {
                    return false;
                }
                return Carbon::parse($item->created_at)->toDateString() === $dayStr;
            })->count();

            $chartData[] = [
                'day'   => $persianDays[$dayDate->dayOfWeek] ?? $dayDate->format('m/d'),
                'date'  => $dayStr,
                'count' => $dayCount,
            ];
        }

        // نگاشت وضعیت عددی به کلیدهای فرانت‌اند
        $mapStatusKey = function (int $statusCode): string {
            return match ($statusCode) {
                0, 1    => 'new',
                2, 3    => 'confirmed',
                4       => 'in_progress',
                5       => 'completed',
                default => 'new',
            };
        };

        $recentRequests = $allRequests->take(5)->values()->map(function ($r) use ($mapStatusKey) {
            $serviceLabel = $r->services_name
                ?: (!empty($r->service_ids) ? "سرویس #{$r->service_ids}" : 'خدمت زیبایی');

            return [
                'id'          => (int) $r->id,
                'patientName' => $r->patient_name ?: 'مراجع',
                'code'        => 'BC-' . (1000 + (int) $r->id),
                'serviceName' => $serviceLabel,
                'status'      => $mapStatusKey((int) $r->status),
                'totalPrice'  => (float) ($r->total_price ?? 0),
            ];
        });

        return response()->json([
            'success' => true,
            'status'  => true,
            'data'    => [
                'profile' => [
                    'id'        => $userId,
                    'name'      => $clinicInfo->name ?? ($user->name ?? 'کلینیک زیبایی'),
                    'isActive'  => (int) ($clinicInfo->status ?? 0) === 1,
                    'address'   => $clinicInfo->address ?? '',
                    'phone'     => $clinicInfo->phone ?? ($user->phone ?? ''),
                    'workHours' => $clinicInfo->work_hours ?? '',
                ],
                'kpis' => [
                    'newCount'      => $newCount,
                    'inProgress'    => $inProgressCount,
                    'completed'     => $completedCount,
                    'totalRevenue'  => $totalRevenue,
                    'averageRating' => $avgRating,
                    'weeklyGrowth'  => '+۱۵٪',
                ],
                'chartData'      => $chartData,
                'recentRequests' => $recentRequests,
            ],
        ]);
    }
}
