<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckServiceStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $serviceKey  (مثلاً laboratory, pharmacy)
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, string $serviceKey): Response
    {
        // جستجوی وضعیت سرویس با استفاده از DB::table
        $service = DB::table('doctor.service_types')
            ->where('service_key', $serviceKey)
            ->first();

        // بررسی وجود سرویس و فعال بودن آن (status == 1)
        if (!$service || (int) $service->status !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'این سرویس در حال حاضر غیرفعال است یا وجود ندارد.'
            ], 403);
        }

        return $next($request);
    }
}
