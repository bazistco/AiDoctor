<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function getMyRooms(Request $request): JsonResponse
    {
        $doctorId = $request->user()->id;
        $perPage = $request->input('per_page', 15);
        $page = $request->input('page', 1);
        $offset = ($page - 1) * $perPage;

        $total = DB::table('room_participants')
            ->where('user_id', $doctorId)
            ->where('status', 1) // فقط اتاق‌های فعال
            ->count();

        $rooms = DB::table('room_participants')
            ->join('chat_rooms', 'room_participants.room_id', '=', 'chat_rooms.id')
            ->leftJoin('messages', 'chat_rooms.last_message_id', '=', 'messages.id')
            ->where('room_participants.user_id', $doctorId)
            ->where('room_participants.status', 1)
            ->select(
                'chat_rooms.id as room_id',
                'chat_rooms.name as room_name',
                'messages.message as last_message',
                'messages.created_at as last_message_time',
                'chat_rooms.created_at as room_created_at'
            )
            ->orderBy('messages.created_at', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $roomsWithOpponent = $rooms->map(function($room) use ($doctorId) {
            $opponent = DB::table('room_participants')
                ->join('users', 'room_participants.user_id', '=', 'users.id')
                ->leftJoin('user_status', 'users.id', '=', 'user_status.user_id')
                ->where('room_participants.room_id', $room->room_id)
                ->where('room_participants.user_id', '!=', $doctorId)
                ->where('room_participants.status', 1)
                ->select(
                    'users.id',
                    'users.name',
                    'users.role',
                    'users.avatar',
                    'user_status.is_online',
                    'user_status.last_seen'
                )
                ->first();

            // محاسبه تعداد پیام‌های خوانده‌نشده (اختیاری)
            // اگه جدول read_receipts داری این رو فعال کن
            // $unreadCount = DB::table('messages')
            //     ->where('room_id', $room->room_id)
            //     ->where('user_id', '!=', $doctorId)
            //     ->whereNotExists(function($query) use ($doctorId) {
            //         $query->select(DB::raw(1))
            //             ->from('read_receipts')
            //             ->whereColumn('read_receipts.message_id', 'messages.id')
            //             ->where('read_receipts.user_id', $doctorId);
            //     })
            //     ->count();

            return [
                'room_id' => $room->room_id,
                'room_name' => $room->room_name,
                'last_message' => $room->last_message,
                'last_message_time' => $room->last_message_time,
                'room_created_at' => $room->room_created_at,
                'opponent' => $opponent ? [
                    'id' => $opponent->id,
                    'name' => $opponent->name,
                    'role' => $opponent->role,
                    'avatar' => $opponent->avatar,
                    'is_online' => (bool) ($opponent->is_online ?? false),
                    'last_seen' => $opponent->last_seen,
                ] : null,
                // 'unread_count' => $unreadCount ?? 0,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'rooms' => $roomsWithOpponent,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                ],
            ],
        ]);
    }

    public function getRoomMessages(Request $request, int $roomId): JsonResponse
    {
        $userId = $request->user()->id;
        $limit = min($request->query('limit', 50), 100);
        $offset = $request->query('offset', 0);

        // بررسی دسترسی کاربر به اتاق
        $participant = DB::table('room_participants')
            ->where('room_id', $roomId)
            ->where('user_id', $userId)
            ->where('status', 1)
            ->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'شما به این اتاق دسترسی ندارید'
            ], 403);
        }

        $messages = DB::table('messages')
            ->join('users', 'messages.user_id', '=', 'users.id')
            ->where('messages.room_id', $roomId)
            ->select(
                'messages.id',
                'messages.room_id',
                'messages.user_id',
                'messages.message',
                'messages.created_at',
                'users.name as sender_name',
                'users.avatar as sender_avatar'
            )
            ->orderBy('messages.created_at', 'asc') // قدیمی‌ترین اول (UI معمولاً از پایین می‌خونه)
            ->offset($offset)
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'messages' => $messages,
                'room_id' => $roomId,
            ],
        ]);
    }
}
