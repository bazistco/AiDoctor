<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ChatController extends Controller
{

    public function getAdminRoomMessages(Request $request, int $roomId): JsonResponse
    {

        // دریافت پیام‌های روم به ترتیب صعودی (مطابق انتظار فرانت‌اند)
        $messages = DB::table('messages as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.room_id', $roomId)
            ->orderBy('m.created_at', 'asc')
            ->select(
                'm.id',
                'm.message',
                'm.created_at as sent_at',
                // تعیین نقش فرستنده
                DB::raw("
                CASE
                    WHEN m.user_id IS NULL THEN 'system'
                    WHEN u.role = 'patient' THEN 'user'
                    WHEN u.role = 'doctor' THEN 'doctor'
                    ELSE 'system'
                END as sender
            "),
                // نام فرستنده (یا سیستمی اگر کاربر null باشد)
                DB::raw('COALESCE(u.name, "سیستم") as sender_name'),
            // در صورتی که ستون is_sensitive در جدول messages دارید می‌توانید آن را انتخاب کنید:
            // 'm.is_sensitive',
            )
            ->get()
            ->map(function ($msg) {
                // تنظیم پیش‌فرض برای is_sensitive (در صورت نبود ستون در دیتابیس)
                $msg->is_sensitive = false;
                return $msg;
            });

        // دریافت اطلاعات بیمار شرکت‌کننده در این روم
        $patient = DB::table('room_participants as rp')
            ->join('users as u', 'u.id', '=', 'rp.user_id')
            ->where('rp.room_id', $roomId)
            ->where('u.role', 'patient')
            ->select('u.phone', 'u.name')
            ->first();

        // مقادیر پیش‌فرض شهر و استان
        $province = 'تهران';
        $city = 'تهران';
        $appointment_id = null;


        // خروجی منطبق بر ساختار ApiChatDetails (بدون کلید data اضافی)
        return response()->json([
            'messages'        => $messages,
            'patient_phone'   => $patient->phone ?? null,
            'province'        => $province,
            'city'            => $city,
            'appointment_id'  => $appointment_id,
        ]);
    }
    public function getPatientRooms(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);
        $offset = ($page - 1) * $perPage;

        // کوئری اصلی با تمام JOINها
        $query = DB::table('users as u')
            ->join('room_participants as rp', 'rp.user_id', '=', 'u.id')
            ->join('chat_rooms as c', 'c.id', '=', 'rp.room_id')
            ->leftJoin('messages as lm', 'lm.id', '=', 'c.last_message_id')
            ->where('u.role', 'patient');

        // گرفتن کل تعداد رکوردها
        $total = (clone $query)->count();

        // گرفتن داده‌های صفحه‌بندی شده
        $rows = $query->select(
            'u.id as patient_id',
            'u.name as patient_name',
            'u.phone',
            'rp.room_id',
            'rp.status',
            'c.name as chat_name',
            'lm.message as last_message',
            'lm.created_at as last_message_at'
        )
            ->orderByDesc('lm.created_at')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        // بهینه‌سازی: استفاده از یک کوئری برای گرفتن همه اطلاعات دکترها
        $roomIds = $rows->pluck('room_id')->unique();

        if ($roomIds->isNotEmpty()) {
            // گرفتن اطلاعات دکترها به صورت یکجا
            $doctorsInfo = DB::table('room_participants as rp2')
                ->join('users as u2', 'u2.id', '=', 'rp2.user_id')
                ->whereIn('rp2.room_id', $roomIds)
                //->where('u2.role', 'doctor')
                ->select(
                    'rp2.room_id',
                    'u2.id as doctor_id',
                    'u2.name as doctor_name',
                    'u2.role'
                )
                ->get()
                ->groupBy('room_id');

            // اضافه کردن اطلاعات دکترها
            $rows = $rows->map(function ($row) use ($doctorsInfo) {
                $roomDoctors = $doctorsInfo->get($row->room_id, collect());

                // اگر چند دکتر وجود دارد، اولین را انتخاب کنید
                $doctor = $roomDoctors->first();

                if ($doctor) {
                    $row->opponent_id = $doctor->doctor_id;
                    $row->role = $doctor->role;
                    $row->opponent_name = $doctor->doctor_name;
                } else {
                    $row->opponent_id = null;
                    $row->role = null;
                    $row->opponent_name = null;
                }

                return $row;
            });
        }

        return response()->json([
            'data' => $rows,
            'current_page' => (int)$page,
            'per_page' => (int)$perPage,
            'total' => $total,
            'last_page' => (int)ceil($total / $perPage),
        ]);
    }

    public function getPatientRoomsTemp(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $page    = $request->get('page', 1);
        $offset  = ($page - 1) * $perPage;

        $base = DB::table('users as u')
            ->join('room_participants as rp', 'rp.user_id', '=', 'u.id')
            ->join('chat_rooms as c', 'rp.room_id', '=', 'c.id')
            // پیدا کردن شریک اتاق (دکتر)
            ->join(DB::raw('(SELECT MIN(rp2_sub.user_id) as user_id, rp2_sub.room_id FROM room_participants as rp2_sub JOIN users as u2 ON u2.id = rp2_sub.user_id AND u2.role = "doctor" GROUP BY rp2_sub.room_id) as rp2'), 'rp2.room_id', '=', 'rp.room_id')
            ->join('users as doc', 'doc.id', '=', 'rp2.user_id')
            ->where('u.role', 'patient')
            ->where('doc.role', 'doctor');

        $total = (clone $base)->count();

//        $rows = DB::table('users as u')
//            ->join('room_participants as rp', 'rp.user_id', '=', 'u.id')
//            ->join(DB::raw('(SELECT MIN(rp2_sub.user_id) as user_id, rp2_sub.room_id FROM room_participants as rp2_sub JOIN users as u2 ON u2.id = rp2_sub.user_id AND u2.role = "doctor" GROUP BY rp2_sub.room_id) as rp2'), 'rp2.room_id', '=', 'rp.room_id')
//            ->join('users as doc', 'doc.id', '=', 'rp2.user_id')
//            ->join('chat_rooms as c', 'c.id', '=', 'rp.room_id')
//            //  ->leftJoin(DB::raw('(SELECT MAX(id) as max_id, room_id FROM messages GROUP BY room_id) as lm_ids'), 'lm_ids.room_id', '=', 'rp.room_id')
//            //  ->leftJoin('messages as lm', 'lm.id', '=', 'lm_ids.max_id')
//            ->leftJoin('messages as lm', 'lm.id', '=', 'c.last_message_id')
//            ->where('u.role', 'patient')
//            ->where('doc.role', 'doctor')
//            ->select(
//                'u.id as patient_id', 'u.name as patient_name',
//                'doc.id as doctor_id', 'doc.name as doctor_name',
//                'rp.room_id', 'c.name as chat_name',
//                'lm.message as last_message', 'lm.created_at as last_message_at'
//            )
//            ->orderByDesc('lm.created_at')
//            ->limit($perPage)->offset($offset)
//            ->get();
//
//
//        return response()->json([
//            'data'         => $rows,
//            'current_page' => (int) $page,
//            'per_page'     => (int) $perPage,
//            'total'        => $total,
//            'last_page'    => (int) ceil($total / $perPage),
//        ]);
        $rows = DB::table('users as u')
            ->join('room_participants as rp', 'rp.user_id', '=', 'u.id')
            ->join('chat_rooms as c', 'c.id', '=', 'rp.room_id')
            ->leftJoin('messages as lm', 'lm.id', '=', 'c.last_message_id')
            ->where('u.role', 'patient')
            ->select(
                'u.id as patient_id', 'u.name as patient_name',
                'rp.room_id', 'rp.status','c.name as chat_name',
                'lm.message as last_message', 'lm.created_at as last_message_at'
            )
            ->orderByDesc('lm.created_at')
            ->limit($perPage)->offset($offset)
            ->get();

// گرفتن doctor_id به ازای room_idهای این صفحه
        $roomIds = $rows->pluck('room_id')->unique()->values();

        $doctorMap = DB::table('room_participants as rp2')
            ->join('users as u2', 'u2.id', '=', 'rp2.user_id')
            ->whereIn('rp2.room_id', $roomIds)
            // ->where('u2.role', 'doctor')
            ->select('rp2.room_id', DB::raw('MIN(rp2.user_id) as doctor_id'))
            ->groupBy('rp2.room_id')
            ->get()
            ->keyBy('room_id');

// گرفتن اطلاعات دکترها
        $doctorIds = $doctorMap->pluck('doctor_id')->unique()->filter();

        $doctors = DB::table('users')
            ->whereIn('id', $doctorIds)
            ->select('id', 'name','role')
            ->get()
            ->keyBy('id');

// map
        $rows = $rows->map(function ($row) use ($doctorMap, $doctors) {
            $doctorId = $doctorMap[$row->room_id]->doctor_id ?? null;
            $row->opponent_id = $doctorId;
            $row->role=$doctors[$doctorId]->role??null;
            $row->opponent_name = $doctorId ? ($doctors[$doctorId]->name ?? null) : null;
            return $row;
        });


        return response()->json([
            'data'         => $rows,
            'current_page' => (int) $page,
            'per_page'     => (int) $perPage,
            'total'        => $total,
            'last_page'    => (int) ceil($total / $perPage),]);
    }


    public function getDoctorRooms(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $page    = $request->get('page', 1);
        $offset  = ($page - 1) * $perPage;

        $total = DB::table('users as u')
            ->leftJoin('doctor_info as di', 'di.user_id', '=', 'u.id')
            ->leftJoin('specialties as s', 'di.specialty_id', '=', 's.id')
            ->join('room_participants as ra', 'ra.user_id', '=', 'u.id')
            ->join('chat_rooms as c', 'ra.room_id', '=', 'c.id')
            ->where('u.role', 'doctor')
            ->count();

//        $rows = DB::table('users as u')
//            ->leftJoin('doctor_info as di', 'di.user_id', '=', 'u.id')
//            ->leftJoin('specialties as s', 'di.specialty_id', '=', 's.id')
//            ->join('room_participants as ra', 'ra.user_id', '=', 'u.id')
//            ->join('chat_rooms as c', 'ra.room_id', '=', 'c.id')
//            ->leftJoin(DB::raw('(SELECT room_id, message, created_at FROM messages WHERE id IN (SELECT MAX(id) FROM messages GROUP BY room_id)) AS lm'), 'lm.room_id', '=', 'ra.room_id')
//            ->where('u.role', 'doctor')
//            ->select(
//                'u.id',
//                'u.name',
//                'di.phone',
//                's.name as specialty',
//                'ra.room_id',
//                'c.name as chat_name',
//                'ra.status',
//                'lm.message as last_message',
//                'lm.created_at as last_message_at'
//            )
//            ->orderByDesc('lm.created_at')
//            ->limit($perPage)
//            ->offset($offset)
//            ->get();
        // کوئری اصلی بدون join به rp2
        $rows = DB::table('users as u')
            ->join('room_participants as rp', 'rp.user_id', '=', 'u.id')
            ->join('chat_rooms as c', 'c.id', '=', 'rp.room_id')
            ->leftJoin('messages as lm', 'lm.id', '=', 'c.last_message_id')
            ->where('u.role', 'patient')
            ->select(
                'u.id as patient_id', 'u.name as patient_name',
                'rp.room_id', 'rp.status','c.name as chat_name',
                'lm.message as last_message', 'lm.created_at as last_message_at'
            )
            ->orderByDesc('lm.created_at')
            ->limit($perPage)->offset($offset)
            ->get();

// گرفتن doctor_id به ازای room_idهای این صفحه
        $roomIds = $rows->pluck('room_id')->unique()->values();

        $doctorMap = DB::table('room_participants as rp2')
            ->join('users as u2', 'u2.id', '=', 'rp2.user_id')
            ->whereIn('rp2.room_id', $roomIds)
           // ->where('u2.role', 'doctor')
            ->select('rp2.room_id', DB::raw('MIN(rp2.user_id) as doctor_id'))
            ->groupBy('rp2.room_id')
            ->get()
            ->keyBy('room_id');

// گرفتن اطلاعات دکترها
        $doctorIds = $doctorMap->pluck('doctor_id')->unique()->filter();

        $doctors = DB::table('users')
            ->whereIn('id', $doctorIds)
            ->select('id', 'name')
            ->get()
            ->keyBy('id');

// map
        $rows = $rows->map(function ($row) use ($doctorMap, $doctors) {
            $doctorId = $doctorMap[$row->room_id]->doctor_id ?? null;
            $row->opponent_id = $doctorId;
            $row->role=$doctors[$doctorId]->role??null;
            $row->opponent_name = $doctorId ? ($doctors[$doctorId]->name ?? null) : null;
            return $row;
        });


        return response()->json([
            'data'         => $rows,
            'current_page' => (int) $page,
            'per_page'     => (int) $perPage,
            'total'        => $total,
            'last_page'    => (int) ceil($total / $perPage),]);
    }
    /**
     * ایجاد اتاق چت جدید بین کاربر و دکتر
     */
    public function createRoom(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'doctor_id' => 'required|integer|exists:users,id',
            'name' => 'nullable|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = auth()->user()->id;
        $doctorId = $request->doctor_id;

        // بررسی وجود اتاق قبلی بین این دو کاربر
        $existingRoom = DB::table('chat_rooms')
            ->join('room_participants as rp1', 'chat_rooms.id', '=', 'rp1.room_id')
            ->join('room_participants as rp2', 'chat_rooms.id', '=', 'rp2.room_id')
            ->where('rp1.user_id', $userId)
            ->where('rp2.user_id', $doctorId)
            ->select('chat_rooms.id', 'chat_rooms.name', 'chat_rooms.created_at')
            ->first();

        if ($existingRoom) {
            return response()->json([
                'success' => true,
                'room_id' => $existingRoom->id,
                'message' => 'اتاق چت قبلاً ایجاد شده است',
                'data' => [
                    'id' => $existingRoom->id,
                    'name' => $existingRoom->name,
                    'created_at' => $existingRoom->created_at
                ]
            ]);
        }

        DB::beginTransaction();
        try {
            // ایجاد اتاق جدید
            $roomId = DB::table('chat_rooms')->insertGetId([
                'name' => $request->name ?? "چت با دکتر #{$doctorId}",
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // اضافه کردن شرکت‌کنندگان
            DB::table('room_participants')->insert([
                [
                    'room_id' => $roomId,
                    'user_id' => $userId,
                    'joined_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now()
                ],
                [
                    'room_id' => $roomId,
                    'user_id' => $doctorId,
                    'joined_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            ]);

            DB::commit();

            $room = DB::table('chat_rooms')->where('id', $roomId)->first();

            return response()->json([
                'success' => true,
                'room_id' => $room->id,
                'message' => 'اتاق چت با موفقیت ایجاد شد',
                'data' => [
                    'id' => $room->id,
                    'name' => $room->name,
                    'created_at' => $room->created_at
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'خطا در ایجاد اتاق چت'
            ], 500);
        }
    }


    /**
     * دریافت لیست اتاق‌های کاربر
     */
    public function getUserRooms(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $rooms = DB::table('chat_rooms')
            ->join('room_participants as rp1', 'chat_rooms.id', '=', 'rp1.room_id')
            ->leftJoin('room_participants as rp2', function($join) use ($userId) {
                $join->on('chat_rooms.id', '=', 'rp2.room_id')
                    ->where('rp2.user_id', '!=', $userId);
            })
            ->where('rp1.user_id', $userId)
            ->select(
                'chat_rooms.id',
                'chat_rooms.name',
                'chat_rooms.created_at',
                'rp2.user_id as other_participant'
            )
            ->orderBy('chat_rooms.created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rooms
        ]);
    }

    /**
     * دریافت پیام‌های یک اتاق
     */
    public function getRoomMessages(Request $request, int $roomId): JsonResponse
    {
        $userId = $request->user()->id;
        $limit = min($request->query('limit', 50), 100);
        $offset = $request->query('offset', 0);

        // بررسی دسترسی کاربر به اتاق
        $participant = DB::table('room_participants')
            ->where('room_id', $roomId)
            ->where('user_id', $userId)
            ->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'شما به این اتاق دسترسی ندارید'
            ], 403);
        }

        $messages = DB::table('messages')
            ->where('room_id', $roomId)
            ->orderBy('created_at', 'desc')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(function ($message) {
                return [
                    'id' => $message->id,
                    'room_id' => $message->room_id,
                    'user_id' => $message->user_id,
                    'message' => $message->message,
                    'created_at' => $message->created_at
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $messages
        ]);
    }

    /**
     * دریافت لیست شرکت‌کنندگان و وضعیت آنلاین
     */
    public function getRoomParticipants(Request $request, int $roomId): JsonResponse
    {
        $userId = $request->user()->id;

        // بررسی دسترسی
        $participant = DB::table('room_participants')
            ->where('room_id', $roomId)
            ->where('user_id', $userId)
            ->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'شما به این اتاق دسترسی ندارید'
            ], 403);
        }

        $participants = DB::table('room_participants')
            ->leftJoin('user_status', 'room_participants.user_id', '=', 'user_status.user_id')
            ->where('room_participants.room_id', $roomId)
            ->select(
                'room_participants.user_id',
                'room_participants.joined_at',
                DB::raw('COALESCE(user_status.is_online, 0) as is_online'),
                'user_status.last_seen'
            )
            ->get();

        return response()->json([
            'success' => true,
            'data' => $participants
        ]);
    }

    /**
     * دریافت وضعیت آنلاین کاربر
     */
    public function getUserStatus(Request $request, int $userId): JsonResponse
    {
        $status = DB::table('user_status')
            ->where('user_id', $userId)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $userId,
                'is_online' => $status->is_online ?? 0,
                'last_seen' => $status->last_seen ?? null
            ]
        ]);
    }
}
