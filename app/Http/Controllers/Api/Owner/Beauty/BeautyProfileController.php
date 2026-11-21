<?php

namespace App\Http\Controllers\Api\Owner\Beauty;

use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BeautyProfileController extends \App\Http\Controllers\Controller
{

    use ApiResponse;

    public function show(Request $request)
    {
        $labId = $request->lab_id;

        $lab = DB::table('beauty_clinic_info')
            ->where('user_id', $labId)
            ->first();

        if (!$lab) {
            return $this->success(null);
        }

        $data = (array) $lab;
        $data['image_url'] = !empty($lab->image)
            ? (str_starts_with($lab->image, 'http')
                ? $lab->image
                : 'https://api.mediraai.com/storage/' . ltrim($lab->image, '/'))
            : null;

        return $this->success($data);
    }
}
