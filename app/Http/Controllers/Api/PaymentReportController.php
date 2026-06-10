<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinancialService;
use Illuminate\Http\Request;

class PaymentReportController extends Controller
{
    public function index(Request $request, FinancialService $service)
    {
        $perPage = min((int) $request->query('per_page', 15), 100);

        return success_response($service->getPaymentReport($perPage));

    }
}
