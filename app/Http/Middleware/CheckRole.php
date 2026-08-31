<?php
// app/Http/Middleware/CheckRole.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * بررسی نقش کاربر (تک‌نقشی، از ستون users.role)
     * استفاده: ->middleware('role:lab,pharmacy')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'احراز هویت نشده',
            ], 401);
        }

        if (!in_array($user->role, $roles, true)) {
            return response()->json([
                'status'  => false,
                'message' => 'شما به این بخش دسترسی ندارید',
            ], 403);
        }

        return $next($request);
    }
}
