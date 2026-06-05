<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CheckApiAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user->role != 'admin') {
            return response()->json(['success' => false, 'message' => 'احراز هویت نشده'], 401);
        }



        return $next($request);
    }
}
