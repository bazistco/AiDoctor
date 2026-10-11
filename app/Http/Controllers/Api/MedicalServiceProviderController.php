<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
        $provider = DB::table("{$tableName} as pa")
            ->join('users as u', 'pa.user_id', '=', 'u.id')
            ->leftJoin('cities as c', 'u.city_id', '=', 'c.id')
            ->where('pa.user_id', $id)
            ->select(
                'u.id as provider_id',
                'pa.name',
                'pa.address',
                'pa.avg_score as rating',
                'c.name as city',
                'pa.phone',
                "{$hoursField} as hours",
                'pa.detail as description',
                'pa.lat',
                'pa.lng',
                'pa.image', // ۱. فیلد تصویر افزوده شد
                'pa.home_acceptance', // ۲. وضعیت پذیرش در منزل
                'pa.in_person_acceptance' // ۳. وضعیت پذیرش حضوری
            )
            ->first();

        if (!$provider) {
            return response()->json(['status' => 'error', 'message' => 'مرکز یافت نشد'], 404);
        }

        // ساخت لینک کامل عکس
        $provider->image_url = !empty($provider->image)
            ? (str_starts_with($provider->image, 'http')
                ? $provider->image
                : 'https://api.mediraai.com/storage/' . ltrim($provider->image, '/'))
            : null;

        // کست کردن دقیق فیلدها برای جلوگیری از خطاهای فرانت‌اند
        $provider->lat = !is_null($provider->lat) ? (float) $provider->lat : null;
        $provider->lng = !is_null($provider->lng) ? (float) $provider->lng : null;
        $provider->rating = !is_null($provider->rating) ? (float) $provider->rating : 5.0;
        $provider->home_acceptance = (bool) ($provider->home_acceptance ?? false);
        $provider->in_person_acceptance = (bool) ($provider->in_person_acceptance ?? false);

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
                'u.name as author'
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
        $labs = DB::table('labs_info as pa')
            ->join('users as u', 'pa.user_id', '=', 'u.id')
            ->leftJoin('cities as c', 'u.city_id', '=', 'c.id')
            ->select(
                'u.id as provider_id',
                'pa.name',
                'pa.address',
                'pa.lat',
                'pa.lng',
                'pa.status',
                'pa.avg_score as rating',
                'pa.image',
                'pa.home_acceptance',
                'pa.in_person_acceptance',
                'c.name as city'
            )
            ->orderByDesc('pa.avg_score')
            ->limit(10)
            ->get()
            ->map(function ($lab) {
                $lab->image_url = !empty($lab->image)
                    ? 'https://api.mediraai.com/storage/' . ltrim($lab->image, '/')
                    : null;

                $lab->home_acceptance = (bool) ($lab->home_acceptance ?? false);
                $lab->in_person_acceptance = (bool) ($lab->in_person_acceptance ?? false);

                // کست مختصات برای خروجی JSON تمیز
                $lab->lat = isset($lab->lat) ? (float) $lab->lat : null;
                $lab->lng = isset($lab->lng) ? (float) $lab->lng : null;

                return $lab;
            });

        $pharmacies = DB::table('pharmacies_info as pa')
            ->join('users as u', 'pa.user_id', '=', 'u.id')
            ->leftJoin('cities as c', 'u.city_id', '=', 'c.id')
            ->select(
                'u.id as provider_id',
                'pa.name',
                'pa.address',
                'pa.status',
                'pa.avg_score as rating',
                'c.name as city'
            )
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
