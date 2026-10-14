<?php

namespace App\Http\Controllers\Api\Staff\Doctor;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class DoctorStaffScheduleController extends Controller
{
    protected function getDoctorId(int $secretaryUserId): ?int
    {
        return DB::table('doctor_secretaries')
            ->where('secretary_user_id', $secretaryUserId)
            ->value('doctor_user_id');
    }
    public function index(Request $request): JsonResponse
    {
        // دریافت ID پزشک که توسط میدلور doctor_secretary تزریق شده است
        $doctorId = $this->getDoctorId($request->user()->id);
        if (!$doctorId) {
            return response()->json([
                'success' => false,
                'message' => 'شناسه پزشک مرتبط یافت نشد.',
            ], Response::HTTP_NOT_FOUND);
        }

        // واکشی قوانین هفتگی از ردیس
        $redisKey = "doctor_schedule_rules:{$doctorId}";
        $rawRules = Redis::get($redisKey);
        $rules = $rawRules ? json_decode($rawRules, true) : [];

        // ترتیب و نام روزهای تقویم شمسی
        // بر اساس اندیس‌های تقویم: 6 = شنبه تا 5 = جمعه
        $daysMap = [
            6 => 'شنبه',
            0 => 'یکشنبه',
            1 => 'دوشنبه',
            2 => 'سه‌شنبه',
            3 => 'چهارشنبه',
            4 => 'پنج‌شنبه',
            5 => 'جمعه',
        ];

        $schedule = [];

        foreach ($daysMap as $dayIndex => $dayName) {
            $dayRule = $rules[$dayIndex] ?? null;
            $shifts = [];
            $slotMinutes = 15;

            if ($dayRule && !empty($dayRule['shifts'])) {
                $slotMinutes = (int) ($dayRule['slot_minutes'] ?? 15);
                foreach ($dayRule['shifts'] as $shift) {
                    if (!empty($shift['start']) && !empty($shift['end'])) {
                        $shifts[] = [
                            'start' => substr($shift['start'], 0, 5),
                            'end'   => substr($shift['end'], 0, 5),
                        ];
                    }
                }
            }

            $schedule[] = [
                'dayIndex'    => $dayIndex,
                'weekday'     => $dayName,
                'slotMinutes' => $slotMinutes,
                'shifts'      => $shifts,
                'isClosed'    => empty($shifts),
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => $schedule,
        ]);
    }
}

