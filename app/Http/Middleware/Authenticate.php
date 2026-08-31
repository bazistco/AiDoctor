<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * مسیری که کاربر احراز نشده باید به آن ریدایرکت شود.
     */
    protected function redirectTo($request): ?string
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return null; // برای API ریدایرکت نکن، 401 JSON برگردان
        }
return null;
      //  return route('login'); // فقط برای درخواست وب
    }
}
