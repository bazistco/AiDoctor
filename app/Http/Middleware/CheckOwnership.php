<?php
// app/Http/Middleware/CheckOwnership.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckOwnership
{
    /**
     * بررسی مالکیت entity برای نقش‌های مالک
     * استفاده: ->middleware('ownership:lab') یا 'ownership:pharmacy' یا 'ownership:medical_center'
     */
    public function handle(Request $request, Closure $next, string $entityType): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'احراز هویت نشده',
            ], 401);
        }
       
        $tableMap = [
            'lab'            => 'labs_info',
            'pharmacy'       => 'pharmacies_info',
            'medical_center' => 'medical_centers_info',
        ];

        if (!isset($tableMap[$entityType])) {
            return response()->json([
                'status'  => false,
                'message' => 'نوع entity نامعتبر است',
            ], 500);
        }

        $table = $tableMap[$entityType];

        $entityId = DB::table($table)->where('user_id', $user->id)->value('user_id');

        if (!$entityId) {
            $messages = [
                'lab'            => 'آزمایشگاهی برای شما ثبت نشده است',
                'pharmacy'       => 'داروخانه‌ای برای شما ثبت نشده است',
                'medical_center' => 'مرکز درمانی برای شما ثبت نشده است',
            ];

            return response()->json([
                'status'  => false,
                'message' => $messages[$entityType] ?? 'دسترسی غیرمجاز',
            ], 403);
        }

        // شناسه entity رو به request اضافه می‌کنیم تا در کنترلر استفاده بشه
        $request->merge(["{$entityType}_id" => $entityId]);

        return $next($request);
    }
}
