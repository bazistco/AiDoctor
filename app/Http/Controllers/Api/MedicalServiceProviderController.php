<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MedicalServiceProviderController extends Controller
{
    public function activeServices()
    {
        $services = DB::table('service_types')
            ->where('status', 1)
            ->select('id', 'name', 'service_key', 'description')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $services
        ]);
    }

    public function show($type, $id)
    {
        if (!in_array($type, ['lab', 'pharmacy'])) {
            return response()->json(['status' => 'error', 'message' => 'نوع نامعتبر است'], 400);
        }

        $tableName = $type === 'lab' ? 'labs_info' : 'pharmacies_info';
        $hoursField = $type === 'lab' ? 'pa.work_hours' : 'pa.working_hours';

        // دریافت اطلاعات پایه مرکز
        $provider = DB::table("$tableName as pa")
            ->join('users as u', 'pa.user_id', '=', 'u.id')
            ->leftJoin('cities as c', 'u.city_id', '=', 'c.id') // leftJoin بهتر است چون ممکن است شهر null باشد
            ->where('pa.user_id', $id)
            ->select(
                'u.id as provider_id',
                'pa.name',
                'pa.address',
                'pa.avg_score as rating',
                'c.name as city',
                'pa.phone',
                "$hoursField as hours",
                'pa.detail as description',
                'pa.lat',
                'pa.lng'
            )
            ->first();

        if (!$provider) {
            return response()->json(['status' => 'error', 'message' => 'مرکز یافت نشد'], 404);
        }

        // دریافت نظرات به همراه نام کاربر نظردهنده
        $reviews = DB::table('reviews as r')
            ->join('users as u', 'r.user_id', '=', 'u.id')
            ->where('r.provider_id', $id)
            ->where('r.order_type', $type)
            ->orderByDesc('r.created_at')
            ->limit(5)
            ->select(
                'r.rating',
                'r.comment as text',
                'r.created_at as date',
                'u.name as author' // اگر در جدول users فیلد name دارید، u.name بنویسید. در غیر این صورت موبایل یا شناسه.
            )
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'provider' => $provider,
                'reviews' => $reviews,
                'services' => []
            ]
        ]);
    }

    public function index(Request $request)
    {
        // دریافت لیست آزمایشگاه‌ها
        $labs = DB::table('labs_info as pa')
            ->join('users as u', 'pa.user_id', '=', 'u.id')
            ->join('cities as c', 'u.city_id', '=', 'c.id')
            ->select(
                'u.id as provider_id',
                'pa.name',
                'pa.address',
                'pa.status',
                'pa.avg_score as rating',
                'c.name as city'
            )
            // در صورت نیاز می‌توانید شرط فعال بودن را اضافه کنید:
            // ->where('pa.status', 1)
            ->orderByDesc('pa.avg_score') // مرتب‌سازی بر اساس بالاترین امتیاز
            ->limit(10)
            ->get();

        // دریافت لیست داروخانه‌ها
        $pharmacies = DB::table('pharmacies_info as pa')
            ->join('users as u', 'pa.user_id', '=', 'u.id')
            ->join('cities as c', 'u.city_id', '=', 'c.id')
            ->select(
                'u.id as provider_id',
                'pa.name',
                'pa.address',
                'pa.status',
                'pa.avg_score as rating',
                'c.name as city'
            )
            // ->where('pa.status', 1)
            ->orderByDesc('pa.avg_score')
            ->limit(10)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'labs' => $labs,
                'pharmacies' => $pharmacies
            ]
        ], 200);
    }
}
