<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppointmentManagementController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',

            'patient_name' => 'nullable|string|max:255',
            'patient_phone' => 'nullable|string|max:50',
            'doctor_id' => 'nullable|integer',
            'status' => 'nullable|string|in:available,booked,done,cancelled',
            'province' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        $perPage = $validated['per_page'] ?? 15;

        $query = DB::table('appointment_slots as a')
            ->leftJoin('users as patient', 'a.patient_id', '=', 'patient.id')
            ->leftJoin('provinces as p', 'patient.province_id', '=', 'p.id')
            ->leftJoin('cities as c', 'patient.city_id', '=', 'c.id')
            ->leftJoin('users as doctor_user', 'a.doctor_id', '=', 'doctor_user.id')
            ->leftJoin('doctor_info as d', 'a.doctor_id', '=', 'd.user_id')
            ->leftJoin('specialties as s', 'd.specialty_id', '=', 's.id')
            ->select(
                'a.id',
                'a.slot_date',
                'a.start_time',
                'a.end_time',
                'a.status',
                'a.patient_id',
                'a.doctor_id',
                'patient.name as patient_name',
                'patient.phone as patient_phone',
                'p.name as province_name',
                'c.name as city_name',
                'doctor_user.name as doctor_name',
                's.name as specialty_name'
            );

        if (!empty($validated['patient_name'])) {
            $query->where('patient.name', 'like', '%' . $validated['patient_name'] . '%');
        }

        if (!empty($validated['patient_phone'])) {
            $query->where('patient.phone', 'like', '%' . $validated['patient_phone'] . '%');
        }

        if (!empty($validated['doctor_id'])) {
            $query->where('a.doctor_id', $validated['doctor_id']);
        }

        if (!empty($validated['status'])) {
            $query->where('a.status', $validated['status']);
        }

        if (!empty($validated['province'])) {
            $query->where('p.name', 'like', '%' . $validated['province'] . '%');
        }

        if (!empty($validated['city'])) {
            $query->where('c.name', 'like', '%' . $validated['city'] . '%');
        }

        if (!empty($validated['date_from'])) {
            $query->whereDate('a.slot_date', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate('a.slot_date', '<=', $validated['date_to']);
        }

        $appointments = $query
            ->orderByDesc('a.slot_date')
            ->orderByDesc('a.start_time')
            ->paginate($perPage);

        $statusMap = [
            'available' => ['text' => 'available', 'color' => 'gray'],
            'booked' => ['text' => 'booked', 'color' => 'blue'],
            'booked' => ['text' => 'booked', 'color' => 'blue'],
            'done' => ['text' => 'done', 'color' => 'green'],
            'canceled' => ['text' => 'canceled', 'color' => 'red'],
        ];

        $data = collect($appointments->items())->map(function ($item) use ($statusMap) {
            $status = $statusMap[$item->status] ?? [
                'text' => $item->status,
                'color' => 'gray',
            ];

            return [
                'id' => $item->id,
                'patient' => [
                    'name' => $item->patient_name ?? '-',
                    'location' => ($item->province_name || $item->city_name)
                        ? trim(($item->province_name ?? '') . ' - ' . ($item->city_name ?? ''), ' -')
                        : '-',
                ],
                'mobile' => $item->patient_phone,
                'doctor' => [
                    'name' => $item->doctor_name ? 'دکتر ' . $item->doctor_name : '-',
                    'specialty' => $item->specialty_name ?? '-',
                ],
                'datetime' => [
                    'date' => $item->slot_date,
                    'time' => substr((string) $item->start_time, 0, 5) . ' - ' . substr((string) $item->end_time, 0, 5),
                ],
                'status' => [
                    'text' => $status['text'],
                    'color' => $status['color'],
                ],
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $appointments->currentPage(),
                'per_page' => $appointments->perPage(),
                'total' => $appointments->total(),
                'last_page' => $appointments->lastPage(),
                'from' => $appointments->firstItem(),
                'to' => $appointments->lastItem(),
            ],
            'links' => [
                'first' => $appointments->url(1),
                'last' => $appointments->url($appointments->lastPage()),
                'prev' => $appointments->previousPageUrl(),
                'next' => $appointments->nextPageUrl(),
            ],
        ]);
    }

    public function doctors()
    {
        $doctors = DB::table('users as u')
            ->leftJoin('doctor_info as d', 'u.id', '=', 'd.user_id')
            ->leftJoin('specialties as s', 'd.specialty_id', '=', 's.id')
            ->where('u.role', 'doctor')
            ->whereNull('u.deleted_at')
            ->select(
                'u.id',
                'u.name',
                's.name as specialty'
            )
            ->orderBy('u.name')
            ->get();

        return response()->json($doctors);
    }

    public function show($id)
    {
        $appointment = DB::table('appointment_slots as a')
            ->leftJoin('users as patient', 'a.patient_id', '=', 'patient.id')
            ->leftJoin('provinces as p', 'patient.province_id', '=', 'p.id')
            ->leftJoin('cities as c', 'patient.city_id', '=', 'c.id')
            ->leftJoin('users as doctor_user', 'a.doctor_id', '=', 'doctor_user.id')
            ->leftJoin('doctor_info as d', 'a.doctor_id', '=', 'd.user_id')
            ->leftJoin('specialties as s', 'd.specialty_id', '=', 's.id')
            ->where('a.id', $id)
            ->select(
                'a.*',
                'patient.name as patient_name',
                'patient.phone as patient_phone',
                'p.name as province_name',
                'c.name as city_name',
                'doctor_user.name as doctor_name',
                'doctor_user.phone as doctor_phone',
                's.name as specialty_name'
            )
            ->first();

        if (!$appointment) {
            return response()->json([
                'message' => 'نوبت پیدا نشد'
            ], 404);
        }

        return response()->json([
            'id' => $appointment->id,
            'slot_date' => $appointment->slot_date,
            'start_time' => $appointment->start_time,
            'end_time' => $appointment->end_time,
            'status' => $appointment->status,
            'patient' => [
                'id' => $appointment->patient_id,
                'name' => $appointment->patient_name,
                'phone' => $appointment->patient_phone,
                'province' => $appointment->province_name,
                'city' => $appointment->city_name,
            ],
            'doctor' => [
                'id' => $appointment->doctor_id,
                'name' => $appointment->doctor_name,
                'phone' => $appointment->doctor_phone,
                'specialty' => $appointment->specialty_name,
            ],
            'booking_time' => $appointment->booking_time,
            'created_at' => $appointment->created_at,
            'updated_at' => $appointment->updated_at,
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:available,booked,completed,cancelled',
            'reason' => 'nullable|string|max:1000',
        ]);

        $exists = DB::table('appointment_slots')->where('id', $id)->first();

        if (!$exists) {
            return response()->json([
                'message' => 'نوبت پیدا نشد'
            ], 404);
        }

        DB::table('appointment_slots')
            ->where('id', $id)
            ->update([
                'status' => $validated['status'],
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'وضعیت نوبت با موفقیت بروزرسانی شد',
        ]);
    }

    public function cancel(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $exists = DB::table('appointment_slots')->where('id', $id)->first();

        if (!$exists) {
            return response()->json([
                'message' => 'نوبت پیدا نشد'
            ], 404);
        }

        DB::table('appointment_slots')
            ->where('id', $id)
            ->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);

        // اگر جدول جدا برای لاگ/دلیل لغو داری اینجا ذخیره کن
        // DB::table('appointment_cancellations')->insert([...]);

        return response()->json([
            'success' => true,
            'message' => 'نوبت با موفقیت لغو شد',
        ]);
    }
}
