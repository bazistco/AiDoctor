<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PillOrganizerController extends Controller
{
    /**
     * دریافت کل اطلاعات جاقرصی کاربر به فرمت cellKey (مثل sat_morn)
     */
    public function index(Request $request): JsonResponse
    {
        $medications = DB::table('user_medications')
            ->where('user_id', $request->user()->id)
            ->orderBy('time')
            ->orderBy('id')
            ->get();

        $grouped = [];

        foreach ($medications as $med) {
            $cellKey = "{$med->day_key}_{$med->slot_key}";
            $grouped[$cellKey][] = $this->formatMedication($med);
        }

        return response()->json([
            'success' => true,
            'data' => (object) $grouped,
        ]);
    }

    /**
     * ثبت دسته‌ای یک یا چند دارو در یک یا چند روز و وعده
     */
    public function storeBulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['required', Rule::in(['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'])],
            'slots' => ['required', 'array', 'min:1'],
            'slots.*' => ['required', Rule::in(['morn', 'noon', 'eve', 'bed'])],
            'medications' => ['required', 'array', 'min:1', 'max:20'],
            'medications.*.name' => ['required', 'string', 'max:255'],
            'medications.*.type' => ['required', Rule::in(['capsule', 'round', 'oval', 'syrup', 'drops', 'ampoule'])],
            'medications.*.count' => ['nullable', 'string', 'max:100'],
            'medications.*.dosage' => ['nullable', 'string', 'max:100'],
            'medications.*.time' => ['nullable', 'string', 'max:10'],
            'medications.*.instruction' => ['nullable', 'string', 'max:100'],
            'medications.*.color' => ['nullable', 'string', 'max:20'],
        ]);

        $userId = $request->user()->id;
        $now = now();
        $recordsToInsert = [];

        foreach ($validated['days'] as $day) {
            foreach ($validated['slots'] as $slot) {
                foreach ($validated['medications'] as $med) {
                    $recordsToInsert[] = [
                        'user_id' => $userId,
                        'day_key' => $day,
                        'slot_key' => $slot,
                        'name' => trim($med['name']),
                        'type' => $med['type'],
                        'count' => !empty($med['count']) ? trim($med['count']) : '۱ عدد',
                        'dosage' => !empty($med['dosage']) ? trim($med['dosage']) : null,
                        'time' => !empty($med['time']) ? trim($med['time']) : '۰۸:۰۰',
                        'instruction' => !empty($med['instruction']) ? trim($med['instruction']) : 'بعد از غذا',
                        'color' => !empty($med['color']) ? $med['color'] : '#2D4739',
                        'is_taken' => 0,
                        'taken_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        DB::transaction(function () use ($recordsToInsert) {
            DB::table('user_medications')->insert($recordsToInsert);
        });

        return $this->index($request);
    }

    /**
     * تغییر وضعیت مصرف (تیک خوردن / نخوردن)
     */
    public function toggleTaken(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;

        $medication = DB::table('user_medications')
            ->where('user_id', $userId)
            ->where('id', $id)
            ->first();

        if (!$medication) {
            return response()->json([
                'success' => false,
                'message' => 'داروی مورد نظر یافت نشد.',
            ], 404);
        }

        $newStatus = !(bool) $medication->is_taken;
        $now = now();

        DB::table('user_medications')
            ->where('user_id', $userId)
            ->where('id', $id)
            ->update([
                'is_taken' => $newStatus ? 1 : 0,
                'taken_at' => $newStatus ? $now : null,
                'updated_at' => $now,
            ]);

        $medication->is_taken = $newStatus;

        return response()->json([
            'success' => true,
            'data' => $this->formatMedication($medication),
        ]);
    }

    /**
     * ویرایش جزئیات یک دارو
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;

        $exists = DB::table('user_medications')
            ->where('user_id', $userId)
            ->where('id', $id)
            ->exists();

        if (!$exists) {
            return response()->json([
                'success' => false,
                'message' => 'داروی مورد نظر یافت نشد.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(['capsule', 'round', 'oval', 'syrup', 'drops', 'ampoule'])],
            'count' => ['nullable', 'string', 'max:100'],
            'dosage' => ['nullable', 'string', 'max:100'],
            'time' => ['nullable', 'string', 'max:10'],
            'instruction' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $validated['updated_at'] = now();

        DB::table('user_medications')
            ->where('user_id', $userId)
            ->where('id', $id)
            ->update($validated);

        $updatedMedication = DB::table('user_medications')
            ->where('id', $id)
            ->first();

        return response()->json([
            'success' => true,
            'data' => $this->formatMedication($updatedMedication),
        ]);
    }

    /**
     * حذف دارو از یک خانه
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = DB::table('user_medications')
            ->where('user_id', $request->user()->id)
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

    /**
     * ریست کردن وضعیت مصرف تمام داروها برای هفته جدید
     */
    public function resetWeek(Request $request): JsonResponse
    {
        DB::table('user_medications')
            ->where('user_id', $request->user()->id)
            ->update([
                'is_taken' => 0,
                'taken_at' => null,
                'updated_at' => now(),
            ]);

        return $this->index($request);
    }

    /**
     * تبدیل رکورد stdClass به فرمت دقیق تایپ MedicationEntry در فرانت‌اند
     */
    private function formatMedication(object $med): array
    {
        return [
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
}
