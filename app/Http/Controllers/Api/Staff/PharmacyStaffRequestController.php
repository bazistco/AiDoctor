<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Services\Payment\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PharmacyStaffRequestController extends Controller
{
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
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(users_prescriptions.details, '$.code')) as prescription_code")
            )
            ->orderBy('users_pharmacy_requests.created_at', 'desc');

        if ($request->has('status') && $request->input('status') !== '' && $request->input('status') !== 'all') {
            $query->where('users_pharmacy_requests.status', $request->input('status'));
        }

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
    public function show($id)
    {
        $pharmacyId = $this->getPharmacyId();
        if (!$pharmacyId) {
            return response()->json(['status' => 'error', 'message' => 'دسترسی غیرمجاز'], 403);
        }

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->leftJoin('users', 'users_pharmacy_requests.user_id', '=', 'users.id')
            ->leftJoin('addresses', 'addresses.id', '=', 'users_pharmacy_requests.user_address_id')
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
                'users_prescriptions.id as prescription_id',
                'users_prescriptions.prescription_type_id',
                'users_prescriptions.status as prescription_status',
                'users_prescriptions.details as prescription_details',
                'users_prescriptions.created_at as prescription_created_at',
                'prescription_types.name as prescription_type_name',
                'addresses.address as address_name',
                'addresses.id as address_id',
                'addresses.lat as address_lat',
                'addresses.lng as address_lng',
            )
            ->first();

        if (!$pharmacyRequest) {
            return response()->json(['status' => 'error', 'message' => 'درخواست نسخه یافت نشد.'], 404);
        }

        if (isset($pharmacyRequest->total_price)) {
            $pharmacyRequest->total_price = $pharmacyRequest->total_price / 10;
        }

        // استخراج اقلام دارویی ثبت شده برای این نسخه
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

    // ---------- acceptRequest: رزرو / برداشتن درخواست (وضعیت 0) ----------
    public function acceptRequest($id)
    {
        $pharmacyId = $this->getPharmacyId();
        if (!$pharmacyId) {
            return response()->json(['status' => 'error', 'message' => 'داروخانه نامعتبر است.'], 403);
        }

        $request = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->whereNull('pharmacy_id')
            ->where('status', 0)
            ->first();

        if (!$request) {
            return response()->json([
                'status'  => 'error',
                'message' => 'این درخواست قبلاً توسط داروخانه دیگری برداشته شده یا دیگر معتبر نیست.'
            ], 400);
        }

        DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->update([
                'pharmacy_id' => $pharmacyId,
                'status'      => 0,
                'updated_at'  => now(),
            ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'درخواست با موفقیت برای داروخانه شما ثبت شد. اکنون می‌توانید قیمت‌گذاری کنید.'
        ]);
    }

    // ---------- releaseRequest: آزادسازی و بازگشت به لیست همگانی (فقط وضعیت 0) ----------
    public function releaseRequest($id)
    {
        $pharmacyId = $this->getPharmacyId();
        if (!$pharmacyId) {
            return response()->json(['status' => 'error', 'message' => 'دسترسی غیرمجاز.'], 403);
        }

        $request = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->where('status', 0)
            ->first();

        if (!$request) {
            return response()->json([
                'status'  => 'error',
                'message' => 'امکان آزادسازی وجود ندارد (درخواست یافت نشد یا وضعیت آن تغییر کرده است).'
            ], 403);
        }

        DB::transaction(function () use ($id) {
            // حذف اقلام ثبت‌شده قبلی روی این درخواست
            DB::table('user_pharmacy_request_medicines')
                ->where('user_pharmacy_request_id', $id)
                ->delete();

            // رهاسازی درخواست
            DB::table('users_pharmacy_requests')
                ->where('id', $id)
                ->update([
                    'pharmacy_id' => null,
                    'status'      => 0,
                    'total_price' => 0,
                    'updated_at'  => now(),
                ]);
        });

        return response()->json([
            'status'  => 'success',
            'message' => 'درخواست با موفقیت آزاد شد و به لیست درخواست‌های عمومی بازگشت.'
        ]);
    }

    // ---------- addItem: افزودن قلم دارو (وضعیت 0) ----------
    public function addItem(Request $request, $id)
    {
        $pharmacyId = $this->getPharmacyId();
        if (!$pharmacyId) {
            return response()->json(['status' => 'error', 'message' => 'دسترسی غیرمجاز'], 403);
        }

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->where('status', 0)
            ->first();

        if (!$pharmacyRequest) {
            return response()->json([
                'status'  => 'error',
                'message' => 'درخواست نامعتبر است یا هنوز توسط شما برداشته نشده است.'
            ], 403);
        }

        // هماهنگی فیلدها با فرانت‌اند
        $medicineId   = $request->input('medicine_id');
        $medicineName = $request->input('medicine_name');
        $qty          = (int) ($request->input('quantity') ?? $request->input('qty', 1));
        $unitPrice    = (float) ($request->input('unit_price') ?? $request->input('price', 0)); // ورودی به تومان
        $unit         = $request->input('unit', 'عدد');

        if (empty($medicineName) && empty($medicineId)) {
            return response()->json(['status' => 'error', 'message' => 'نام یا شناسه دارو را وارد کنید.'], 422);
        }

        DB::transaction(function () use ($pharmacyId, $id, &$medicineId, $medicineName, $qty, $unitPrice, $unit) {
            // اگر دارو قبلا در دیتابیس نبود، ثبت می‌کنیم
            if (empty($medicineId) && !empty($medicineName)) {
                $existingMed = DB::table('medicines')->where('name', $medicineName)->first();
                if ($existingMed) {
                    $medicineId = $existingMed->id;
                } else {
                    $medicineId = DB::table('medicines')->insertGetId([
                        'name'       => $medicineName,
                        'slug'       => Str::slug($medicineName) ?: str_replace(' ', '-', $medicineName),
                        'created_at' => now(),
                    ]);
                }
            }

            // بررسی اتصال به انبار داروخانه
            $pharmacyMedicine = DB::table('pharmacy_medicines')
                ->where('pharmacy_id', $pharmacyId)
                ->where('medicine_id', $medicineId)
                ->first();

            $priceInRial = $unitPrice * 10;

            if (!$pharmacyMedicine) {
                $pharmacyMedicineId = DB::table('pharmacy_medicines')->insertGetId([
                    'pharmacy_id'      => $pharmacyId,
                    'medicine_id'      => $medicineId,
                    'medicine_type_id' => 1,
                    'unit'             => $unit,
                    'price_per_unit'   => $priceInRial,
                    'status'           => 1,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            } else {
                $pharmacyMedicineId = $pharmacyMedicine->id;
                DB::table('pharmacy_medicines')
                    ->where('id', $pharmacyMedicineId)
                    ->update([
                        'price_per_unit' => $priceInRial,
                        'unit'           => $unit,
                        'updated_at'     => now(),
                    ]);
            }

            // ثبت قلم در درخواست بیمار
            DB::table('user_pharmacy_request_medicines')->insert([
                'user_pharmacy_request_id' => $id,
                'pharmacy_medicine_id'     => $pharmacyMedicineId,
                'quantity'                 => $qty,
                'price'                    => $priceInRial,
                'created_at'               => now(),
                'updated_at'               => now(),
            ]);

            // بازشماری جمع کل به ریال در جدول اصلی
            $this->updateRequestTotalPrice($id);
        });

        return response()->json(['status' => 'success', 'message' => 'دارو با موفقیت اضافه شد.']);
    }

    // ---------- removeItem: حذف یک قلم دارو (وضعیت 0) ----------
    public function removeItem($id, $itemId)
    {
        $pharmacyId = $this->getPharmacyId();

        $request = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->where('status', 0)
            ->first();

        if (!$request) {
            return response()->json(['status' => 'error', 'message' => 'دسترسی غیرمجاز یا وضعیت نامعتبر.'], 403);
        }

        DB::table('user_pharmacy_request_medicines')
            ->where('id', $itemId)
            ->where('user_pharmacy_request_id', $id)
            ->delete();

        $this->updateRequestTotalPrice($id);

        return response()->json(['status' => 'success', 'message' => 'دارو با موفقیت حذف شد.']);
    }

    // ---------- acceptAndIssueInvoice: تأیید نهایی و صدور فاکتور پرداخت (وضعیت 0 -> 1) ----------
    public function acceptAndIssueInvoice(Request $request, $id, OrderService $orderService)
    {
        $pharmacyId = $this->getPharmacyId();
        if (!$pharmacyId) {
            return response()->json(['status' => 'error', 'message' => 'دسترسی غیرمجاز.'], 403);
        }

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->where('status', 0)
            ->first();

        if (!$pharmacyRequest) {
            return response()->json(['status' => 'error', 'message' => 'درخواست معتبر نیست یا وضعیت آن بررسی نشده است.'], 403);
        }

        if ($pharmacyRequest->total_price <= 0) {
            return response()->json(['status' => 'error', 'message' => 'لطفاً ابتدا حداقل یک دارو و قیمت آن را ثبت کنید.'], 422);
        }

        DB::beginTransaction();
        try {
            DB::table('users_pharmacy_requests')
                ->where('id', $id)
                ->update([
                    'status'     => 1,
                    'updated_at' => now(),
                ]);

            // صدور سفارش مالی با reason_id = 6 (پرداخت فاکتور داروخانه)
            $orderService->createOrReuse(
                userId: $pharmacyRequest->user_id,
                reasonId: 6,
                reasonRef: $id,
                amount: $pharmacyRequest->total_price,
                description: "پرداخت فاکتور داروخانه - درخواست #{$id}",
                providerId: $pharmacyId
            );

            DB::commit();

            return response()->json(['status' => 'success', 'message' => 'نسخه تأیید و فاکتور پرداخت برای بیمار ارسال شد.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'خطا در صدور فاکتور: ' . $e->getMessage()], 500);
        }
    }

    // ---------- دانلود امن و استریم فایل نسخه ----------
    public function downloadPrescription($requestId, $fileName)
    {
        $pharmacyId = $this->getPharmacyId();

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->where('id', $requestId)
            ->where(function ($query) use ($pharmacyId) {
                $query->where('pharmacy_id', $pharmacyId)
                    ->orWhereNull('pharmacy_id');
            })
            ->first();

        if (!$pharmacyRequest) {
            return response()->json(['success' => false, 'message' => 'دسترسی مشاهده این نسخه وجود ندارد.'], 403);
        }

        $cleanFileName = str_replace(['prescriptions/', '\\'], ['', '/'], $fileName);
        $targetPath = 'prescriptions/' . $cleanFileName;

        if (!Storage::disk('local')->exists($targetPath)) {
            return response()->json([
                'success' => false,
                'message' => 'فایل نسخه در سرور یافت نشد.',
                'target'  => $targetPath,
            ], 404);
        }

        $pureFileName = basename($targetPath);
        $mimeType = Storage::disk('local')->mimeType($targetPath);

        return Storage::disk('local')->response($targetPath, $pureFileName, [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $pureFileName . '"',
        ]);
    }

    // ---------- تغییر مراحل وضعیت (1 تا 6) ----------
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
        return $this->changeStatus($id, $this->getPharmacyId(), 3, 4, 'وضعیت به "در حال ارسال با پیک" تغییر یافت.');
    }

    public function markAsDelivered($id) {
        $pharmacyId = $this->getPharmacyId();
        $affected = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->whereIn('status', [3, 4])
            ->update(['status' => 5, 'updated_at' => now()]);

        if ($affected === 0) return response()->json(['status' => 'error', 'message' => 'عملیات نامعتبر است.'], 403);
        return response()->json(['status' => 'success', 'message' => 'نسخه به بیمار تحویل داده شد.']);
    }

    // ---------- cancelRequest: لغو درخواست ----------
    public function cancelRequest($id)
    {
        $pharmacyId = $this->getPharmacyId();
        if (!$pharmacyId) return response()->json(['status' => 'error'], 403);

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->whereIn('status', [0, 1])
            ->first();

        if (!$pharmacyRequest) {
            return response()->json(['status' => 'error', 'message' => 'این سفارش غیرقابل لغو است.'], 403);
        }

        DB::beginTransaction();
        try {
            DB::table('users_pharmacy_requests')
                ->where('id', $id)
                ->update(['status' => 7, 'updated_at' => now()]);

            if ($pharmacyRequest->status == 1) {
                DB::table('orders')
                    ->where('user_id', $pharmacyRequest->user_id)
                    ->where('reason_id', 6)
                    ->where('reason_ref', $id)
                    ->where('status', 1)
                    ->update([
                        'status'       => 3,
                        'cancelled_at' => now(),
                        'updated_at'   => now(),
                    ]);
            }
            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'درخواست با موفقیت لغو شد.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'خطا در لغو درخواست.'], 500);
        }
    }

    // ---------- متد کمکی بروزرسانی مجموع مبلغ نسخه ----------
    private function updateRequestTotalPrice($requestId)
    {
        $total = DB::table('user_pharmacy_request_medicines')
            ->where('user_pharmacy_request_id', $requestId)
            ->sum(DB::raw('quantity * price'));

        DB::table('users_pharmacy_requests')
            ->where('id', $requestId)
            ->update([
                'total_price' => $total,
                'updated_at'  => now(),
            ]);
    }
}
