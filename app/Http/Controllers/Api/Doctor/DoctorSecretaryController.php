<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DoctorSecretaryController extends Controller
{
    /**
     * دریافت لیست منشی‌های پزشک
     */
    public function index(Request $request): JsonResponse
    {
        $doctorUserId = $request->user()->id;

        $secretaries = DB::table('doctor_secretaries')
            ->join('users', 'doctor_secretaries.secretary_user_id', '=', 'users.id')
            ->where('doctor_secretaries.doctor_user_id', $doctorUserId)
            ->whereNull('users.deleted_at')
            ->select([
                'doctor_secretaries.id as relationship_id',
                'users.id as id',
                'users.name',
                'users.phone',
                'users.status',
                'doctor_secretaries.created_at',
            ])
            ->orderBy('doctor_secretaries.id', 'desc')
            ->get()
            ->map(function ($sec) {
                return [
                    'id'        => (string) $sec->id,
                    'name'      => $sec->name,
                    'phone'     => $sec->phone,
                    'active'    => (int) $sec->status === 1,
                    'createdAt' => $sec->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $secretaries,
        ]);
    }

    /**
     * فعال / غیرفعال کردن دسترسی منشی
     */
    public function toggleStatus(Request $request, int|string $id): JsonResponse
    {
        $doctorUserId = $request->user()->id;

        // بررسی اینکه منشی متعلق به همین پزشک باشد
        $relation = DB::table('doctor_secretaries')
            ->where('doctor_user_id', $doctorUserId)
            ->where('secretary_user_id', $id)
            ->first();

        if (!$relation) {
            return response()->json([
                'success' => false,
                'message' => 'منشی مورد نظر برای این حساب یافت نشد.',
            ], Response::HTTP_NOT_FOUND);
        }

        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'کاربر یافت نشد.',
            ], Response::HTTP_NOT_FOUND);
        }

        $newStatus = ((int) $user->status === 1) ? 0 : 1;

        DB::table('users')->where('id', $id)->update([
            'status'     => $newStatus,
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => $newStatus === 1 ? 'حساب منشی با موفقیت فعال شد.' : 'حساب منشی غیرفعال شد.',
            'data'    => [
                'id'     => (string) $id,
                'active' => $newStatus === 1,
            ],
        ]);
    }

    /**
     * حذف ارتباط منشی با پزشک
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $doctorUserId = $request->user()->id;

        $deleted = DB::table('doctor_secretaries')
            ->where('doctor_user_id', $doctorUserId)
            ->where('secretary_user_id', $id)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'منشی مورد نظر یافت نشد.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'message' => 'منشی با موفقیت حذف شد.',
        ]);
    }
}
