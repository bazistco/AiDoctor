<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PillShareCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PillOrganizerShareController extends Controller
{
    public function cronGenerateMissingCodes(Request $request, PillShareCodeService $codeService): JsonResponse
    {
        $secret = 'sosmost666';
        $providedKey = $request->header('X-Cron-Key') ?? $request->query('key');

        if (empty($secret) || !hash_equals((string) $secret, (string) $providedKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized cron request.',
            ], 403);
        }

        $limit = min(max((int) $request->query('limit', 500), 1), 2000);
        $processed = 0;

        $users = DB::table('users')
            ->whereNull('pill_share_code')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($users as $userId) {
            $codeService->ensureCodeForUser((int) $userId);
            $processed++;
        }

        $remaining = DB::table('users')
            ->whereNull('pill_share_code')
            ->count();

        return response()->json([
            'success' => true,
            'processed' => $processed,
            'remaining' => $remaining,
        ]);
    }
    /**
     * دریافت یا تولید کد دعوت اختصاصی کاربر فعلی
     */
    public function myCode(Request $request): JsonResponse
    {
        $user = $request->user();
        $code = $user->pill_share_code;

        if (!$code) {
            do {
                $code = strtoupper(Str::random(8));
            } while (DB::table('users')->where('pill_share_code', $code)->exists());

            DB::table('users')
                ->where('id', $user->id)
                ->update(['pill_share_code' => $code]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'share_code' => $code,
                'invite_path' => "/pill-invite/{$code}",
            ],
        ]);
    }

    /**
     * لیست افرادی که تقویم آن‌ها با کاربر فعلی به اشتراک گذاشته شده است
     */
    public function sharedUsers(Request $request): JsonResponse
    {
        $viewerId = $request->user()->id;

        $users = DB::table('pill_organizer_shares as s')
            ->join('users as u', 'u.id', '=', 's.owner_user_id')
            ->where('s.viewer_user_id', $viewerId)
            ->select([
                'u.id',
                'u.name',
                'u.pill_share_code as share_code',
                's.can_delete',
            ])
            ->orderByDesc('s.updated_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * پذیرش کد دعوت (پس از ورود یا ثبت‌نام از طریق لینک دعوت)
     */
    public function acceptInvite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $code = strtoupper(trim($validated['code']));
        $viewerId = $request->user()->id;

        $owner = DB::table('users')
            ->where('pill_share_code', $code)
            ->first();

        if (!$owner) {
            return response()->json([
                'success' => false,
                'message' => 'کد دعوت معتبر نیست.',
            ], 404);
        }

        if ((int) $owner->id === (int) $viewerId) {
            return response()->json([
                'success' => false,
                'message' => 'این کد متعلق به حساب کاربری خود شماست.',
            ], 422);
        }

        DB::table('pill_organizer_shares')->updateOrInsert(
            [
                'owner_user_id' => $owner->id,
                'viewer_user_id' => $viewerId,
            ],
            [
                'share_code' => $code,
                'can_delete' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'دسترسی به تقویم دارویی با موفقیت فعال شد.',
            'data' => [
                'id' => $owner->id,
                'name' => $owner->name,
                'share_code' => $code,
                'can_delete' => true,
            ],
        ]);
    }

    /**
     * جستجوی تقویم فرد بر اساس کد اختصاصی او و اضافه کردن به لیست تقویم‌های قابل مشاهده
     */
    public function searchByCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $code = strtoupper(trim($validated['code']));
        $viewerId = $request->user()->id;

        $owner = DB::table('users')
            ->where('pill_share_code', $code)
            ->first();

        if (!$owner) {
            return response()->json([
                'success' => false,
                'message' => 'کاربری با این کد یافت نشد.',
            ], 404);
        }

        if ((int) $owner->id === (int) $viewerId) {
            return response()->json([
                'success' => false,
                'message' => 'این کد مربوط به تقویم خود شماست؛ از بخش «برای خودم» استفاده کنید.',
            ], 422);
        }

        DB::table('pill_organizer_shares')->updateOrInsert(
            [
                'owner_user_id' => $owner->id,
                'viewer_user_id' => $viewerId,
            ],
            [
                'share_code' => $code,
                'can_delete' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $owner->id,
                'name' => $owner->name,
                'share_code' => $code,
                'can_delete' => true,
            ],
        ]);
    }

    /**
     * دریافت تقویم دارویی یک فرد اشتراک‌گذاری‌شده (فقط خواندنی + حذف)
     */
    public function viewSharedCalendar(Request $request, int $ownerId): JsonResponse
    {
        $viewerId = $request->user()->id;

        $share = DB::table('pill_organizer_shares')
            ->where('owner_user_id', $ownerId)
            ->where('viewer_user_id', $viewerId)
            ->first();

        if (!$share) {
            return response()->json([
                'success' => false,
                'message' => 'شما به تقویم این کاربر دسترسی ندارید.',
            ], 403);
        }

        $owner = DB::table('users')->where('id', $ownerId)->first();

        $medications = DB::table('user_medications')
            ->where('user_id', $ownerId)
            ->orderBy('time')
            ->orderBy('id')
            ->get();

        $grouped = [];
        foreach ($medications as $med) {
            $cellKey = "{$med->day_key}_{$med->slot_key}";
            $grouped[$cellKey][] = [
                'id' => (string) $med->id,
                'name' => $med->name,
                'type' => $med->type,
                'count' => $med->count,
                'dosage' => $med->dosage ?? '',
                'time' => $med->time,
                'instruction' => $med->instruction,
                'color' => $med->color,
                'taken' => (bool) $med->is_taken,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'owner' => [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'share_code' => $owner->pill_share_code,
                ],
                'can_delete' => (bool) $share->can_delete,
                'pillBox' => (object) $grouped,
            ],
        ]);
    }

    /**
     * حذف یک دارو از تقویم فرد اشتراک‌گذاری‌شده
     */
    public function deleteSharedMedication(Request $request, int $ownerId, int $id): JsonResponse
    {
        $viewerId = $request->user()->id;

        $share = DB::table('pill_organizer_shares')
            ->where('owner_user_id', $ownerId)
            ->where('viewer_user_id', $viewerId)
            ->first();

        if (!$share || !$share->can_delete) {
            return response()->json([
                'success' => false,
                'message' => 'اجازه حذف دارو برای شما وجود ندارد.',
            ], 403);
        }

        $deleted = DB::table('user_medications')
            ->where('user_id', $ownerId)
            ->where('id', $id)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'داروی مورد نظر یافت نشد.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'دارو با موفقیت حذف شد.',
        ]);
    }
}
