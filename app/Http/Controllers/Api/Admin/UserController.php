<?php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    private function baseQuery()
    {
        return DB::table('users as u')
            ->join('cities as c', 'c.id', '=', 'u.city_id')
            ->join('provinces as p', 'p.id', '=', 'u.province_id')
            ->leftJoin('doctor_info as di', 'di.user_id', '=', 'u.id')
            ->select(
                'u.id', 'u.name', 'u.email', 'u.phone',
                'u.role', 'u.status', 'u.is_verify', 'u.avatar',
                'u.created_at', 'u.updated_at',
                'c.name as city', 'p.name as province',
                // فیلدهای مربوط به پزشک
                'di.document_status', 'di.document_rejection_reason',
                'di.national_code', 'di.is_national_code_verified',
                'di.medical_code',
                'di.national_card_image', 'di.medical_council_card_image', 'di.clinic_license_image'
            );
    }

    private function formatUser(object $user): array
    {
        // تابع کمکی برای تولید لینک دانلود ایمن برای ادمین
        // توجه: می‌توانید یک روت اختصاصی برای دانلود ادمین بسازید یا از Storage::url استفاده کنید.
        $getDocUrl = fn($path) => $path ? secure_url('/api/admin/documents/download?path=' . urlencode($path)) : null;

        return [
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'phone'      => $user->phone,
            'role'       => $user->role,
            'type'       => $user->role,
            'status'     => $user->status,
            'is_verify'  => (bool) $user->is_verify,
            'isVerified' => (bool) $user->is_verify,
            'avatar'     => $user->avatar ?? null,
            'city'       => $user->city,
            'province'   => $user->province,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,

            // اطلاعات احراز و مدارک پزشک
            'doctor_info' => $user->role === 'doctor' ? [
                'national_code'             => $user->national_code,
                'is_national_code_verified' => (bool) $user->is_national_code_verified,
                'medical_code'              => $user->medical_code,
                'document_status'           => $user->document_status ?? 'not_uploaded',
                'document_rejection_reason' => $user->document_rejection_reason,
                'documents' => [
                    'national_card'        => $getDocUrl($user->national_card_image),
                    'medical_council_card' => $getDocUrl($user->medical_council_card_image),
                    'clinic_license'       => $getDocUrl($user->clinic_license_image),
                ]
            ] : null,
        ];
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery()->whereNull('u.deleted_at');

        if ($request->filled('name'))
            $query->where('u.name', 'like', "%{$request->name}%");

        if ($request->filled('phone'))
            $query->where('u.phone', 'like', "%{$request->phone}%");

        if ($request->filled('type') && $request->type !== 'all')
            $query->where('u.role', $request->type);

        if ($request->filled('status') && $request->status !== 'all')
            $query->where('u.status', $request->status);

        if ($request->filled('province'))
            $query->where('p.name', $request->province);

        if ($request->filled('city'))
            $query->where('c.name', $request->city);

        $query->orderBy('u.created_at', 'DESC');
        $perPage = $request->get('per_page', 8);
        $page    = $request->get('page', 1);

        $total   = (clone $query)->count();

        $users   = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'data'         => $users->map(fn($u) => $this->formatUser($u)),
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
            ],
        ]);
    }


    public function show(int $id)
    {
        $user = $this->baseQuery()->where('u.id', $id)->first();
        if (!$user) return response()->json(['success' => false, 'message' => 'کاربر یافت نشد'], 404);

        return response()->json(['success' => true, 'data' => $this->formatUser($user)]);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            // اعتبارسنجی جدول users
            'name'      => 'sometimes|string|max:100',
            'email'     => 'sometimes|nullable|email',
            'phone'     => 'sometimes|nullable|string',
            'role'      => 'sometimes|string',
            'gender'    => 'sometimes|in:0,1',
            'is_verify' => 'sometimes|in:0,1',
            'status'    => 'sometimes|in:0,1',

            // اعتبارسنجی مدارک پزشک (doctor_info)
            'document_status'           => 'sometimes|in:pending,approved,rejected',
            'document_rejection_reason' => 'sometimes|nullable|string',
            'is_national_code_verified' => 'sometimes|boolean',
            'national_code'             => 'sometimes|nullable|string',
        ]);

        // ۱. آپدیت جدول Users
        $userData = collect($data)->only(['name', 'email', 'phone', 'role', 'gender', 'is_verify', 'status'])->toArray();
        if (!empty($userData)) {
            DB::table('users')->where('id', $id)->update(array_merge($userData, ['updated_at' => now()]));
        }

        // ۲. آپدیت جدول Doctor Info (در صورت وجود دیتای مربوطه)
        $doctorData = collect($data)->only(['document_status', 'document_rejection_reason', 'is_national_code_verified', 'national_code'])->toArray();

        // اگر وضعیت تایید شد، دلیل رد باید پاک شود
        if (isset($doctorData['document_status']) && $doctorData['document_status'] === 'approved') {
            $doctorData['document_rejection_reason'] = null;
        }

        if (!empty($doctorData)) {
            DB::table('doctor_info')->where('user_id', $id)->update(array_merge($doctorData, ['updated_at' => now()]));
        }

        return response()->json(['success' => true, 'data' => $this->formatUser($this->baseQuery()->where('u.id', $id)->first())]);
    }

    public function destroy(int $id)
    {
          DB::table('users')->where('id', $id)->update(['deleted_at' => now()]);
        return response()->json(['success' => true]);
    }

    public function bulkStatus(Request $request)
    {
        $request->validate(['ids' => 'required|array', 'ids.*' => 'integer', 'status' => 'required|in:0,1']);
        DB::table('users')->whereIn('id', $request->ids)->update(['status' => $request->status, 'updated_at' => now()]);
        return response()->json(['success' => true]);
    }

      public function bulkDelete(Request $request)
    {
        $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        DB::table('users')->whereIn('id', $request->ids)->update(['deleted_at' => now()]);
        return response()->json(['success' => true]);
    }
    public function previewDocument(Request $request)
    {
        $path = $request->query('path');

        // بررسی وجود مسیر و فایل (فرض بر این است که فایل‌ها در دیسک public یا local هستند)
        if (!$path || !Storage::disk('local')->exists($path)) {
            return response()->json(['success' => false, 'message' => 'فایل یافت نشد یا حذف شده است.'], 404);
        }

        $file = Storage::disk('local')->path($path);
        $mimeType = Storage::disk('local')->mimeType($path);

        // ارسال فایل به صورت مستقیم تا مرورگر بتواند آن را رندر کند یا دانلود کند
        return response()->file($file, [
            'Content-Type' => $mimeType,
            'Access-Control-Expose-Headers' => 'Content-Disposition'
        ]);
    }

    /**
     * فراخوانی سرویس شاهکار (فینوتک) برای تطابق کد ملی و موبایل
     */
    public function verifyShahkar(Request $request, int $id)
    {
        $request->validate([
            'national_code' => 'required|string|size:10'
        ]);

        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'کاربر یافت نشد'], 404);
        }

        $mobile = $user->phone;
        $nationalCode = $request->national_code;

        // TODO: در محیط پروداکشن، کلاینت‌کد و توکن باید از فایل config('services.finotech.client_id') خوانده شود
        $finotechClientId = env('FINOTECH_CLIENT_ID', 'YOUR_CLIENT_ID');
        $finotechToken = env('FINOTECH_TOKEN', 'YOUR_ACCESS_TOKEN');

        try {
            // فراخوانی API شاهکار فینوتک
            $response = Http::withToken($finotechToken)
                ->timeout(10)
                ->get("https://api.finotech.ir/ccg/v1/clients/{$finotechClientId}/shahkar/verify", [
                    'mobile' => $mobile,
                    'nationalCode' => $nationalCode
                ]);

            if ($response->successful() && isset($response->json()['result']['isMatch'])) {
                $isMatch = $response->json()['result']['isMatch'];

                if ($isMatch) {
                    // آپدیت دیتابیس در صورت تطابق
                    DB::table('doctor_info')->updateOrInsert(
                        ['user_id' => $id],
                        [
                            'national_code' => $nationalCode,
                            'is_national_code_verified' => 1,
                            'updated_at' => now()
                        ]
                    );

                    return response()->json(['success' => true, 'message' => 'احراز هویت با موفقیت تایید شد.']);
                } else {
                    return response()->json(['success' => false, 'message' => 'کد ملی با شماره موبایل کاربر تطابق ندارد!'], 400);
                }
            }

            return response()->json(['success' => false, 'message' => 'خطا در ارتباط با سرویس شاهکار.', 'details' => $response->json()], 502);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'سرویس شاهکار در حال حاضر در دسترس نیست.'], 500);
        }
    }
}
