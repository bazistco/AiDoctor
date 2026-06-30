<?php
// app/Http/Controllers/Admin/BaseController.php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;

abstract class BaseController extends Controller
{
    use ApiResponse;
}
