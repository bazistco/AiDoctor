<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PeriodShareController extends Controller
{
    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'داده‌های ورودی نامعتبر است.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = auth()->id();

        $tracker = DB::table('period_trackers')
            ->where('user_id', $userId)
            ->first();

        if (!$tracker) {
            return response()->json([
                'status' => false,
                'message' => 'ابتدا اطلاعات پریود را ثبت کنید.',
            ], 404);
        }

        DB::table('period_share_links')
            ->where('user_id', $userId)
            ->update([
                'is_active' => 0,
                'updated_at' => now(),
            ]);

        $token = Str::random(64);

        $id = DB::table('period_share_links')->insertGetId([
            'user_id' => $userId,
            'period_tracker_id' => $tracker->id,
            'token' => $token,
            'expires_at' => $request->expires_at,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $share = DB::table('period_share_links')->where('id', $id)->first();

        return response()->json([
            'status' => true,
            'message' => 'لینک اشتراک ساخته شد.',
            'data' => [
                'id' => $share->id,
                'token' => $share->token,
                'share_url' => url('/api/user/period-tracker/shared/' . $share->token),
                'expires_at' => $share->expires_at,
                'is_active' => (bool) $share->is_active,
            ],
        ], 201);
    }

    public function disable(Request $request)
    {
        $userId = auth()->id();

        $updated = DB::table('period_share_links')
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->update([
                'is_active' => 0,
                'updated_at' => now(),
            ]);

        return response()->json([
            'status' => true,
            'message' => $updated ? 'لینک اشتراک غیرفعال شد.' : 'لینک فعالی یافت نشد.',
        ]);
    }

    public function activeLink(Request $request)
    {
        $userId = auth()->id();

        $share = DB::table('period_share_links')
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->orderByDesc('id')
            ->first();

        if (!$share) {
            return response()->json([
                'status' => true,
                'message' => 'لینک فعالی وجود ندارد.',
                'data' => null,
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'لینک فعال دریافت شد.',
            'data' => [
                'id' => $share->id,
                'token' => $share->token,
                'share_url' => url('/api/user/period-tracker/shared/' . $share->token),
                'expires_at' => $share->expires_at,
                'is_active' => (bool) $share->is_active,
            ],
        ]);
    }

    public function showShared($token)
    {
        $share = DB::table('period_share_links')
            ->join('period_trackers', 'period_share_links.period_tracker_id', '=', 'period_trackers.id')
            ->where('period_share_links.token', $token)
            ->where('period_share_links.is_active', 1)
            ->select(
                'period_share_links.id as share_id',
                'period_share_links.expires_at',
                'period_trackers.last_period_start_date',
                'period_trackers.last_period_end_date',
                'period_trackers.cycle_length',
                'period_trackers.period_length'
            )
            ->first();

        if (!$share) {
            return response()->json([
                'status' => false,
                'message' => 'لینک اشتراک معتبر نیست.',
            ], 404);
        }

        if ($share->expires_at && now()->gt($share->expires_at)) {
            return response()->json([
                'status' => false,
                'message' => 'لینک منقضی شده است.',
            ], 410);
        }

        return response()->json([
            'status' => true,
            'message' => 'اطلاعات اشتراک دریافت شد.',
            'data' => [
                'last_period_start_date' => $share->last_period_start_date,
                'last_period_end_date' => $share->last_period_end_date,
                'cycle_length' => $share->cycle_length,
                'period_length' => $share->period_length,
                'expires_at' => $share->expires_at,
            ],
        ]);
    }
}
