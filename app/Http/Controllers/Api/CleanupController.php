<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class CleanupController extends Controller
{
    /**
     * آزادسازی نوبت‌های موقت منقضی شده
     */
    public function releaseExpiredSlots(Request $request)
    {
        // یک لایه امنیتی ساده: چک کردن توکن در هدر یا پارامتر برای جلوگیری از سوءاستفاده
        // در فایل .env مقدار CRON_SECRET_TOKEN=your_secure_random_string را تنظیم کنید
        $secretToken = env('CRON_SECRET_TOKEN', 'sosmost666');
        if ($request->header('X-Cron-Token') !== $secretToken) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $processedCount = 0;
        $releasedCount = 0;

        try {
            // استفاده از chunkById به جای chunk برای جلوگیری از جا افتادن رکوردها هنگام آپدیت
            DB::table('appointment_slots')
                ->where('status', 'available')
                ->whereNotNull('patient_id')
                ->chunkById(100, function ($slots) use (&$processedCount, &$releasedCount) {

                    $slotIds = $slots->pluck('id')->toArray();

                    // باز کردن تراکنش دیتابیس
                    DB::transaction(function () use ($slotIds, &$processedCount, &$releasedCount) {

                        // قفل کردن رکوردها در دیتابیس (Pessimistic Locking)
                        $lockedSlots = DB::table('appointment_slots')
                            ->whereIn('id', $slotIds)
                            ->lockForUpdate()
                            ->get();

                        foreach ($lockedSlots as $slot) {
                            $processedCount++;
                            $redisKey = "slot:reservation:{$slot->id}";
                            $redisData = Redis::get($redisKey);

                            // اگر ردیس خالی بود و نوبت در وضعیت locking نبود
                            if (!$redisData && $redisData !== 'locking...') {
                                // آزادسازی نوبت
                                $affected = DB::table('appointment_slots')
                                    ->where('id', $slot->id)
                                    ->where('status', 'available')
                                    ->update([
                                        'patient_id' => null,
                                        'updated_at' => now()
                                    ]);

                                if ($affected > 0) {
                                    $releasedCount++;
                                }
                            }
                        }
                    });
                });


            Log::info("Cron Cleanup: Processed {$processedCount} slots, Released {$releasedCount} slots.");

            return response()->json([
                'success'   => true,
                'message'   => 'پاکسازی با موفقیت انجام شد.',
                'processed' => $processedCount,
                'released'  => $releasedCount
            ]);

        } catch (\Exception $e) {
            Log::error("Cron Cleanup Error: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'خطا در عملیات پاکسازی',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
