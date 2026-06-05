<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ChatController extends Controller
{
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
