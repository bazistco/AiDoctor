<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Services\Payment\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PharmacyStaffRequestController extends Controller
{
    public function addItem(Request $request, $id)
    {
        $pharmacyId = $this->getPharmacyId($request);

        $validated = $request->validate([
            'medicine_name' => 'required|string|max:191',
            'quantity'      => 'required|integer|min:1',
            'unit'          => 'nullable|string|max:50',
            'unit_price'    => 'required|numeric|min:0',
            'description'   => 'nullable|string|max:255',
        ]);

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->first();

        if (!$pharmacyRequest) {
            return response()->json(['message' => 'درخواست یافت نشد'], 404);
        }

        if (!in_array($pharmacyRequest->status, [0, 1])) {
            return response()->json(['message' => 'امکان ویرایش اقلام در این وضعیت وجود ندارد'], 422);
        }

        $itemTotalPrice = $validated['quantity'] * $validated['unit_price'];

        DB::transaction(function () use ($id, $validated, $itemTotalPrice) {
            // درج دارو در جدول اقلام
            DB::table('users_pharmacy_requests_items')->insert([
                'user_pharmacy_request_id' => $id,
                'medicine_name'            => $validated['medicine_name'],
                'quantity'                 => $validated['quantity'],
                'unit'                     => $validated['unit'] ?? 'عدد',
                'unit_price'               => $validated['unit_price']*10,
                'total_price'              => $itemTotalPrice*10,
                'description'              => $validated['description'] ?? null,
                'created_at'               => now(),
                'updated_at'               => now(),
            ]);

            // بازشماری مجموع کل فاکتور نسخه
            $newTotal = DB::table('users_pharmacy_requests_items')
                ->where('user_pharmacy_request_id', $id)
                ->sum('total_price');

            DB::table('users_pharmacy_requests')
                ->where('id', $id)
                ->update([
                    'total_price' => $newTotal/10,
                    'updated_at'  => now(),
                ]);
        });

        return response()->json(['message' => 'قلم دارو با موفقیت اضافه شد'], 201);
    }

    /**
     * حذف یک قلم دارو از نسخه
     */
    public function removeItem(Request $request, $id, $itemId)
    {
        $pharmacyId = $this->getPharmacyId($request);

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->first();

        if (!$pharmacyRequest || !in_array($pharmacyRequest->status, [0, 1])) {
            return response()->json(['message' => 'امکان حذف اقلام وجود ندارد'], 422);
        }

        DB::transaction(function () use ($id, $itemId) {
            DB::table('users_pharmacy_requests_items')
                ->where('id', $itemId)
                ->where('user_pharmacy_request_id', $id)
                ->delete();

            // به‌روزرسانی جمع کل
            $newTotal = DB::table('users_pharmacy_requests_items')
                ->where('user_pharmacy_request_id', $id)
                ->sum('total_price') ?? 0;

            DB::table('users_pharmacy_requests')
                ->where('id', $id)
                ->update([
                    'total_price' => $newTotal,
                    'updated_at'  => now(),
                ]);
        });

        return response()->json(['message' => 'قلم دارو حذف شد']);
    }

    /**
     * تأیید نهایی داروها و صدور پیش‌فاکتور برای پرداخت بیمار (تغییر از 0 به 1)
     */
    public function acceptAndIssueInvoice(Request $request, $id)
    {
        $pharmacyId = $this->getPharmacyId($request);

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->first();

        if (!$pharmacyRequest) {
            return response()->json(['message' => 'درخواست یافت نشد'], 404);
        }

        if ($pharmacyRequest->status !== 0) {
            return response()->json(['message' => 'درخواست در وضعیت بررسی اولیه قرار ندارد'], 422);
        }

        $itemsCount = DB::table('users_pharmacy_requests_items')
            ->where('user_pharmacy_request_id', $id)
            ->count();

        if ($itemsCount === 0) {
            return response()->json(['message' => 'حداقل یک قلم دارو باید به نسخه اضافه شود'], 422);
        }

        // تغییر وضعیت به 1 (در انتظار پرداخت بیمار)
        DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->update([
                'status'     => 1,
                'updated_at' => now(),
            ]);

        // در صورت تمایل، ارسال پیامک صدور فاکتور به کاربر لاگین‌شده در اینجا صدا زده می‌شود

        return response()->json(['message' => 'نسخه تأیید و فاکتور جهت پرداخت بیمار صادر شد']);
    }
    /**
     * استخراج شناسه داروخانه‌ای که کارمند در آن مشغول به کار است
     */
    protected function getPharmacyId()
    {
        $userId = auth()->id();

        $staff = DB::table('medical_centers_staffs')
            ->where('staff_id', $userId)
            ->where('center_type', 'pharmacy')
            ->where('is_active', 1)
            ->first();

        return $staff ? $staff->center_id : null;
    }

    // ---------- index: فهرست درخواست‌ها ----------
    public function index(Request $request)
    {
        $pharmacyId = $this->getPharmacyId();
        if (!$pharmacyId) {
            return response()->json(['status' => 'error', 'message' => 'شما به هیچ داروخانه‌ای متصل نیستید.'], 403);
        }

        $query = DB::table('users_pharmacy_requests')
            ->leftJoin('users', 'users_pharmacy_requests.user_id', '=', 'users.id')
            ->leftJoin('users_prescriptions', 'users_pharmacy_requests.prescription_id', '=', 'users_prescriptions.id')
            ->where(function ($q) use ($pharmacyId) {
                $q->where('users_pharmacy_requests.pharmacy_id', $pharmacyId)
                    ->orWhereNull('users_pharmacy_requests.pharmacy_id');
            })
            ->select(
                'users_pharmacy_requests.*',
                'users.name as user_name',
                'users.phone as user_mobile',
                'users.national_code as user_national_code',
                // گرفتن کد نسخه از JSON details در صورت نیاز برای سرچ
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(users_prescriptions.details, '$.code')) as prescription_code")
            )
            ->orderBy('users_pharmacy_requests.created_at', 'desc');

        // فیلتر وضعیت
        if ($request->has('status') && $request->input('status') !== '' && $request->input('status') !== 'all') {
            $query->where('users_pharmacy_requests.status', $request->input('status'));
        }

        // جستجو (نام، موبایل، کد رهگیری نسخه) - مچ شده با فرانت‌اند
        if ($request->has('search') && $request->input('search') !== '') {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.phone', 'like', "%{$search}%")
                    ->orWhere(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(users_prescriptions.details, '$.code'))"), 'like', "%{$search}%");
            });
        }

        $requests = $query->get()->map(function ($item) {
            if (isset($item->total_price)) {
                $item->total_price = $item->total_price / 10;
            }
            return $item;
        });

        return response()->json(['status' => 'success', 'data' => $requests]);
    }

    // ---------- show: نمایش جزئیات یک درخواست ----------

    /**
     * نمایش اطلاعات و جزییات کامل نسخه برای کارمند
     */
    public function show(Request $request, $id)
    {
        $pharmacyId = $this->getPharmacyId($request);

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->leftJoin('users', 'users_pharmacy_requests.user_id', '=', 'users.id')
            ->leftJoin('users_prescriptions', 'users_pharmacy_requests.prescription_id', '=', 'users_prescriptions.id')
            ->leftJoin('prescription_types', 'users_prescriptions.prescription_type_id', '=', 'prescription_types.id')
            ->where('users_pharmacy_requests.id', $id)
            ->where(function ($query) use ($pharmacyId) {
                $query->where('users_pharmacy_requests.pharmacy_id', $pharmacyId)
                    ->orWhereNull('users_pharmacy_requests.pharmacy_id');
            })
            ->select(
                'users_pharmacy_requests.*',
                'users.name as user_name',
                'users.phone as user_mobile',
                'users.national_code as user_national_code',
                // فیلدهای حیاتی نسخه بیمار:
                'users_prescriptions.id as prescription_id',
                'users_prescriptions.prescription_type_id',
                'users_prescriptions.status as prescription_status',
                'users_prescriptions.details as prescription_details',
                'users_prescriptions.created_at as prescription_created_at',
                'prescription_types.name as prescription_type_name'
            )
            ->first();

        if (!$pharmacyRequest) {
            return response()->json(['status' => 'error', 'message' => 'درخواست نسخه یافت نشد.'], 404);
        }

        // تبدیل ریال به تومان برای نمایش در پنل
        if (isset($pharmacyRequest->total_price)) {
            $pharmacyRequest->total_price = $pharmacyRequest->total_price / 10;
        }

        // استخراج نام فایل نسخه از details (در صورتی که json یا متن ساده باشد)
        $prescriptionFile = null;
        if (!empty($pharmacyRequest->prescription_details)) {
            $detailsDecoded = json_decode($pharmacyRequest->prescription_details, true);
            if (is_array($detailsDecoded) && isset($detailsDecoded['file'])) {
                $prescriptionFile = $detailsDecoded['file'];
            } elseif (is_string($pharmacyRequest->prescription_details) && (
                    str_contains($pharmacyRequest->prescription_details, '.jpg') ||
                    str_contains($pharmacyRequest->prescription_details, '.png') ||
                    str_contains($pharmacyRequest->prescription_details, '.jpeg') ||
                    str_contains($pharmacyRequest->prescription_details, '.pdf')
                )) {
                $prescriptionFile = $pharmacyRequest->prescription_details;
            }
        }
        $pharmacyRequest->prescription_file_name = $prescriptionFile;

        // دریافت لیست داروهای افزوده شده به این نسخه
        $items = DB::table('user_pharmacy_request_medicines as uprm')
            ->join('pharmacy_medicines as pm', 'uprm.pharmacy_medicine_id', '=', 'pm.id')
            ->join('medicines', 'pm.medicine_id', '=', 'medicines.id')
            ->leftJoin('medicine_types', 'pm.medicine_type_id', '=', 'medicine_types.id')
            ->where('uprm.user_pharmacy_request_id', $id)
            ->select(
                'uprm.id',
                'pm.medicine_id',
                'uprm.quantity',
                DB::raw('uprm.price / 10 as price'),
                DB::raw('(uprm.quantity * uprm.price / 10) as total_price'),
                'medicines.name as medicine_name',
                DB::raw("COALESCE(medicine_types.name, '-') as medicine_type_name"),
                DB::raw("COALESCE(pm.unit, 'عدد') as unit")
            )
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'request' => $pharmacyRequest,
                'items'   => $items,
            ]
        ]);
    }

    /**
     * دانلود و پیش‌نمایش امن فایل نسخه برای پرسنل
     */
    public function downloadPrescription(Request $request, $requestId, $fileName)
    {
        $pharmacyId = $this->getPharmacyId();
        // ۱. بررسی دسترسی داروخانه به این درخواست
        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->where('id', $requestId)
            ->where(function ($query) use ($pharmacyId) {
                $query->where('pharmacy_id', $pharmacyId)
                    ->orWhereNull('pharmacy_id');
            })
            ->first();

        if (!$pharmacyRequest) {
            return response()->json([
                'success' => false,
                'message' => 'شما دسترسی لازم برای مشاهده این نسخه را ندارید.'
            ], 403);
        }

        // ۲. پاکسازی و ایمن‌سازی مسیر فایل
        $cleanFileName = str_replace(['prescriptions/', '\\'], ['', '/'], $fileName);
        $targetPath = 'prescriptions/' . $cleanFileName;

        // ۳. بررسی وجود فایل در دیسک امن
        if (!Storage::disk('local')->exists($targetPath)) {
            return response()->json([
                'success' => false,
                'message' => 'فایل نسخه در سرور یافت نشد.',
                'target'  => $targetPath
            ], 404);
        }

        $pureFileName = basename($targetPath);
        $mimeType = Storage::disk('local')->mimeType($targetPath);

        // ۴. ارسال استریم فایل با قابلیت پیش‌نمایش در مرورگر
        return Storage::disk('local')->response($targetPath, $pureFileName, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $pureFileName . '"',
        ]);
    }


    // ---------- acceptRequest: رزرو درخواست (وضعیت 0) ----------
    public function acceptRequest($id)
    {
        $pharmacyId = $this->getPharmacyId();
        if (!$pharmacyId) return response()->json(['status' => 'error'], 403);

        $request = DB::table('users_pharmacy_requests')
            ->where('id', $id)->whereNull('pharmacy_id')->where('status', 0)->first();

        if (!$request) return response()->json(['status' => 'error', 'message' => 'نامعتبر'], 400);

        DB::table('users_pharmacy_requests')->where('id', $id)->update([
            'pharmacy_id' => $pharmacyId, 'updated_at' => now()
        ]);

        return response()->json(['status' => 'success', 'message' => 'درخواست برای بررسی رزرو شد.']);
    }

    // ---------- status update methods (1 to 6) ----------
    private function changeStatus($id, $pharmacyId, $currentStatus, $newStatus, $successMessage)
    {
        $affected = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->where('status', $currentStatus)
            ->update(['status' => $newStatus, 'updated_at' => now()]);

        if ($affected === 0) {
            return response()->json(['status' => 'error', 'message' => 'تغییر وضعیت مجاز نیست.'], 403);
        }
        return response()->json(['status' => 'success', 'message' => $successMessage]);
    }

    public function markAsPreparing($id) {
        return $this->changeStatus($id, $this->getPharmacyId(), 1, 2, 'وضعیت به "در حال آماده‌سازی" تغییر یافت.');
    }

    public function markAsReadyForDelivery($id) {
        return $this->changeStatus($id, $this->getPharmacyId(), 2, 3, 'وضعیت به "آماده تحویل/ارسال" تغییر یافت.');
    }

    public function markAsDelivering($id) {
        return $this->changeStatus($id, $this->getPharmacyId(), 3, 4, 'وضعیت به "در حال ارسال" تغییر یافت.');
    }

    public function markAsDelivered($id) {
        // از آماده ارسال(حضوری) یا در حال ارسال(پیک) به تحویل شده
        $pharmacyId = $this->getPharmacyId();
        $affected = DB::table('users_pharmacy_requests')
            ->where('id', $id)->where('pharmacy_id', $pharmacyId)
            ->whereIn('status', [3, 4])
            ->update(['status' => 5, 'updated_at' => now()]);

        if ($affected === 0) return response()->json(['status' => 'error'], 403);
        return response()->json(['status' => 'success', 'message' => 'نسخه تحویل داده شد.']);
    }

    // ---------- cancelRequest: لغو (0 یا 1 -> 7) ----------
    public function cancelRequest($id)
    {
        $pharmacyId = $this->getPharmacyId();
        if (!$pharmacyId) return response()->json(['status' => 'error'], 403);

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->where('id', $id)->where('pharmacy_id', $pharmacyId)->whereIn('status', [0, 1])->first();

        if (!$pharmacyRequest) {
            return response()->json(['status' => 'error', 'message' => 'غیرقابل لغو.'], 403);
        }

        DB::beginTransaction();
        try {
            DB::table('users_pharmacy_requests')->where('id', $id)->update(['status' => 7, 'updated_at' => now()]);

            if ($pharmacyRequest->status == 1) {
                DB::table('orders')->where('user_id', $pharmacyRequest->user_id)
                    ->where('reason_id', 6)->where('reason_ref', $id)->where('status', 1)
                    ->update(['status' => 3, 'cancelled_at' => now(), 'updated_at' => now()]);
            }
            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'درخواست با موفقیت لغو شد.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'خطا در لغو'], 500);
        }
    }


}
