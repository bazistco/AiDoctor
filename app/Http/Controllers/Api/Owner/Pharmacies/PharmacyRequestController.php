<?php

namespace App\Http\Controllers\Api\Owner\Pharmacies;

use App\Http\Controllers\Controller;
use App\Services\Payment\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PharmacyRequestController extends Controller
{
    protected function getPharmacyId()
    {
        return auth()->id();
    }

    // ---------- index: فهرست درخواست‌ها ----------
    public function index(Request $request)
    {
        $pharmacyId = $this->getPharmacyId();

        $query = DB::table('users_pharmacy_requests')
            ->leftJoin('users', 'users_pharmacy_requests.user_id', '=', 'users.id')
            ->where(function ($q) use ($pharmacyId) {
                $q->where('users_pharmacy_requests.pharmacy_id', $pharmacyId)
                    ->orWhereNull('users_pharmacy_requests.pharmacy_id');
            })
            ->select(
                'users_pharmacy_requests.*',
                'users.name as user_name',
                'users.phone as user_mobile',
                'users.national_code as user_national_code'
            )
            ->orderBy('users_pharmacy_requests.created_at', 'desc');

        if ($request->has('status') && $request->input('status') !== '' && $request->input('status') !== 'all') {
            $query->where('users_pharmacy_requests.status', $request->input('status'));
        }

        $requests = $query->get();

        return response()->json(['status' => 'success', 'data' => $requests]);
    }

    // ---------- show: نمایش جزئیات یک درخواست ----------
    public function show($id)
    {
        $pharmacyId = $this->getPharmacyId();

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
                'users_prescriptions.id as prescription_id',
                'users_prescriptions.prescription_type_id',
                'users_prescriptions.status as prescription_status',
                'users_prescriptions.details as prescription_details',
                'users_prescriptions.created_at as prescription_created_at',
                'users_prescriptions.updated_at as prescription_updated_at',
                'prescription_types.name as prescription_type_name'
            )
            ->first();

        if (!$pharmacyRequest) {
            return response()->json(['status' => 'error', 'message' => 'درخواست یافت نشد.'], 404);
        }

        // اقلام درخواست با join روی pharmacy_medicines و medicines
        $items = DB::table('user_pharmacy_request_medicines as uprm')
            ->join('pharmacy_medicines as pm', 'uprm.pharmacy_medicine_id', '=', 'pm.id')
            ->join('medicines', 'pm.medicine_id', '=', 'medicines.id')
            ->leftJoin('medicine_types', 'pm.medicine_type_id', '=', 'medicine_types.id')
            ->where('uprm.user_pharmacy_request_id', $id)
            ->select(
                'uprm.id',
                'pm.medicine_id',
                'uprm.quantity',
                'uprm.price',
                DB::raw('(uprm.quantity * uprm.price) as total_price'),
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

    // ---------- acceptRequest: رزرو درخواست (وضعیت 0) ----------
    public function acceptRequest($id)
    {
        $pharmacyId = $this->getPharmacyId();

        $request = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->whereNull('pharmacy_id')
            ->where('status', 0)
            ->first();

        if (!$request) {
            return response()->json([
                'status' => 'error',
                'message' => 'این درخواست قبلاً رزرو شده یا معتبر نیست.'
            ], 400);
        }

        DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->update([
                'pharmacy_id' => $pharmacyId,
                'status'      => 0,            // وضعیت همچنان 0 (امکان افزودن دارو)
                'updated_at'  => now()
            ]);

        return response()->json([
            'status' => 'success',
            'message' => 'درخواست با موفقیت برای شما رزرو شد. اکنون می‌توانید داروها را اضافه کنید.'
        ]);
    }

    // ---------- releaseRequest: رهاسازی (فقط در وضعیت 0) ----------
    public function releaseRequest($id)
    {
        $pharmacyId = $this->getPharmacyId();

        $request = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->where('status', 0)
            ->first();

        if (!$request) {
            return response()->json([
                'status' => 'error',
                'message' => 'امکان رهاسازی در وضعیت فعلی وجود ندارد.'
            ], 403);
        }

        DB::table('user_pharmacy_request_medicines')
            ->where('user_pharmacy_request_id', $id)
            ->delete();

        DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->update([
                'pharmacy_id' => null,
                'status'      => 0,
                'total_price' => 0,
                'updated_at'  => now()
            ]);

        return response()->json([
            'status' => 'success',
            'message' => 'درخواست رها شد و به لیست آزاد بازگشت.'
        ]);
    }

    // ---------- addItem: افزودن دارو (وضعیت 0) ----------
    public function addItem(Request $request, $id)
    {
        $pharmacyId = $this->getPharmacyId();

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->where('status', 0)
            ->first();

        if (!$pharmacyRequest) {
            return response()->json([
                'status' => 'error',
                'message' => 'دسترسی غیرمجاز یا وضعیت نامعتبر.'
            ], 403);
        }

        $medicineId   = $request->input('medicine_id');
        $medicineName = $request->input('medicine_name'); // اضافه شدن دریافت نام دارو
        $qty          = (int) $request->input('qty', 1);
        $price        = (float) $request->input('price', 0);
        $unit         = $request->input('unit', 'عدد');

        // 🟢 بخش جدید: اگر شناسه دارو نداریم اما نام تایپ شده است (داروی جدید)
        if (empty($medicineId) && !empty($medicineName)) {
            // ابتدا جستجو می‌کنیم تا اگر با همین نام وجود داشت، داروی تکراری نسازیم
            $existingMed = DB::table('medicines')->where('name', $medicineName)->first();

            if ($existingMed) {
                $medicineId = $existingMed->id;
            } else {
                // ثبت داروی جدید در جدول کل داروها (medicines)
                $medicineId = DB::table('medicines')->insertGetId([
                    'name'       => $medicineName,
                    'slug'       => \Illuminate\Support\Str::slug($medicineName) ?: str_replace(' ', '-', $medicineName),
                    'created_at' => now(),
                    // فیلدهای دیگر مانند base_price خالی می‌مانند تا زمانی که ادمین کل بررسی کند
                ]);
            }
        }

        if (empty($medicineId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'دارو مشخص نشده است.'
            ], 400);
        }

        // 🟢 بررسی وجود این دارو در انبار همین داروخانه (pharmacy_medicines)
        $pharmacyMedicine = DB::table('pharmacy_medicines')
            ->where('pharmacy_id', $pharmacyId)
            ->where('medicine_id', $medicineId)
            ->first();

        if (!$pharmacyMedicine) {
            // اضافه کردن مستقیم دارو برای داروخانه به همراه قیمت‌گذاری
            $pharmacyMedicineId = DB::table('pharmacy_medicines')->insertGetId([
                'pharmacy_id'      => $pharmacyId,
                'medicine_id'      => $medicineId,
                'medicine_type_id' => 1,             // نوع پیش‌فرض (مثلاً قرص)
                'unit'             => $unit,
                'price_per_unit'   => $price,
                'status'           => 1,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        } else {
            // اگر قبلا در داروخانه بود فقط قیمت و واحد به‌روز می‌شود
            $pharmacyMedicineId = $pharmacyMedicine->id;
            DB::table('pharmacy_medicines')
                ->where('id', $pharmacyMedicineId)
                ->update([
                    'price_per_unit' => $price,
                    'unit'           => $unit,
                    'updated_at'     => now()
                ]);
        }

        // 🟢 ثبت نهایی دارو در درخواست بیمار
        DB::table('user_pharmacy_request_medicines')->insert([
            'user_pharmacy_request_id' => $id,
            'pharmacy_medicine_id'     => $pharmacyMedicineId,
            'quantity'                 => $qty,
            'price'                    => $price,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $this->updateRequestTotalPrice($id);

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
            return response()->json([
                'status' => 'error',
                'message' => 'دسترسی غیرمجاز یا وضعیت نامعتبر.'
            ], 403);
        }

        DB::table('user_pharmacy_request_medicines')
            ->where('id', $itemId)
            ->where('user_pharmacy_request_id', $id)
            ->delete();

        $this->updateRequestTotalPrice($id);

        return response()->json(['status' => 'success', 'message' => 'دارو با موفقیت حذف شد.']);
    }

    // ---------- searchMedicines: جستجوی داروها ----------
    public function searchMedicines(Request $request)
    {
        $pharmacyId = $this->getPharmacyId();
        $queryParam = $request->input('q');

        $query = DB::table('medicines')
            ->leftJoin('pharmacy_medicines', function ($join) use ($pharmacyId) {
                $join->on('medicines.id', '=', 'pharmacy_medicines.medicine_id')
                    ->where('pharmacy_medicines.pharmacy_id', '=', $pharmacyId);
            })
            ->select(
                'medicines.id',
                'medicines.name',
                'medicines.generic_name',
                'medicines.slug',
                'medicines.base_price',
                'pharmacy_medicines.id as pharmacy_medicine_id',
                'pharmacy_medicines.price_per_unit as pharmacy_price',
                'pharmacy_medicines.unit as pharmacy_unit'
            );

        if ($queryParam) {
            $query->where(function ($q) use ($queryParam) {
                $q->where('medicines.name', 'LIKE', "%{$queryParam}%")
                    ->orWhere('medicines.generic_name', 'LIKE', "%{$queryParam}%")
                    ->orWhere('medicines.slug', 'LIKE', "%{$queryParam}%");
            });
        }

        $medicines = $query->limit(20)->get();

        return response()->json(['status' => 'success', 'data' => $medicines]);
    }

    // ---------- updateStatus: تایید نهایی و ارسال به وضعیت 1 ----------
    // ---------- updateStatus: تایید نهایی و ارسال به وضعیت 1 ----------
    public function updateStatus(Request $request, $id, OrderService $orderService)
    {
        $pharmacyId = $this->getPharmacyId();
        $newStatus  = (int) $request->input('status');

        if ($newStatus !== 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'فقط وضعیت 1 (در انتظار پرداخت) از این طریق قابل تغییر است.'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // ۱. خواندن اطلاعات درخواست
            $pharmacyRequest = DB::table('users_pharmacy_requests')
                ->where('id', $id)
                ->where('pharmacy_id', $pharmacyId)
                ->where('status', 0)
                ->first();

            if (!$pharmacyRequest) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'امکان تغییر وضعیت وجود ندارد یا درخواست یافت نشد.'
                ], 403);
            }

            // اگر مبلغ سفارش صفر باشد خطا می‌دهیم (باید دارو اضافه شده باشد)
            if ($pharmacyRequest->total_price <= 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'مبلغ کل نمی‌تواند صفر باشد. لطفاً ابتدا داروها را اضافه کنید.'
                ], 422);
            }

            // ۲. تغییر وضعیت به ۱
            DB::table('users_pharmacy_requests')
                ->where('id', $id)
                ->update([
                    'status'     => 1,
                    'updated_at' => now()
                ]);
//            $pharmacyRequest->total_price
            // ۳. ایجاد سفارش مالی برای بیمار با reason_id = 6
            $orderService->createOrReuse(
                userId: $pharmacyRequest->user_id, // شناسه بیماری که درخواست را ثبت کرده
                reasonId: 6,                       // دلیل 6: پرداخت فاکتور داروخانه
                reasonRef: $id,                    // متصل به شناسه درخواست داروخانه
                amount: 15000,
                description: "پرداخت فاکتور داروخانه - درخواست #{$id}"
            );

            DB::commit();

            return response()->json(['status' => 'success', 'message' => 'تایید نهایی انجام شد و فاکتور برای بیمار صادر گردید.']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'خطا در ثبت نهایی: ' . $e->getMessage()
            ], 500);
        }
    }

    // ---------- markAsPreparing: 1 -> 2 ----------
    public function markAsPreparing($id)
    {
        $pharmacyId = $this->getPharmacyId();

        $affected = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->where('status', 1)
            ->update([
                'status' => 2,
                'updated_at' => now()
            ]);

        if ($affected === 0) {
            return response()->json(['status' => 'error', 'message' => 'تغییر وضعیت مجاز نیست.'], 403);
        }

        return response()->json(['status' => 'success', 'message' => 'وضعیت به "در حال آماده‌سازی" تغییر یافت.']);
    }

    // ---------- markAsReadyForDelivery: 2 -> 3 ----------
    public function markAsReadyForDelivery($id)
    {
        $pharmacyId = $this->getPharmacyId();

        $affected = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->where('status', 2)
            ->update([
                'status' => 3,
                'updated_at' => now()
            ]);

        if ($affected === 0) {
            return response()->json(['status' => 'error', 'message' => 'تغییر وضعیت مجاز نیست.'], 403);
        }

        return response()->json(['status' => 'success', 'message' => 'وضعیت به "آماده ارسال" تغییر یافت.']);
    }

    // ---------- markAsDelivering: 3 -> 4 ----------
    public function markAsDelivering($id)
    {
        $pharmacyId = $this->getPharmacyId();

        $affected = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->where('status', 3)
            ->update([
                'status' => 4,
                'updated_at' => now()
            ]);

        if ($affected === 0) {
            return response()->json(['status' => 'error', 'message' => 'تغییر وضعیت مجاز نیست.'], 403);
        }

        return response()->json(['status' => 'success', 'message' => 'وضعیت به "در حال ارسال" تغییر یافت.']);
    }

    // ---------- markAsDelivered: 4 -> 5 ----------
    public function markAsDelivered($id)
    {
        $pharmacyId = $this->getPharmacyId();

        $affected = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->where('status', 4)
            ->update([
                'status' => 5,
                'updated_at' => now()
            ]);

        if ($affected === 0) {
            return response()->json(['status' => 'error', 'message' => 'تغییر وضعیت مجاز نیست.'], 403);
        }

        return response()->json(['status' => 'success', 'message' => 'وضعیت به "تحویل شده" تغییر یافت.']);
    }

    // ---------- markAsCompleted: 5 -> 6 ----------
    public function markAsCompleted($id)
    {
        $pharmacyId = $this->getPharmacyId();

        $affected = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->where('status', 5)
            ->update([
                'status' => 6,
                'updated_at' => now()
            ]);

        if ($affected === 0) {
            return response()->json(['status' => 'error', 'message' => 'تغییر وضعیت مجاز نیست.'], 403);
        }

        return response()->json(['status' => 'success', 'message' => 'سفارش تکمیل شد.']);
    }

    // ---------- cancelRequest: لغو (0 یا 1 -> 7) ----------
    // ---------- cancelRequest: لغو (0 یا 1 -> 7) ----------
    public function cancelRequest($id)
    {
        $pharmacyId = $this->getPharmacyId();

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->whereIn('status', [0, 1])
            ->first();

        if (!$pharmacyRequest) {
            return response()->json([
                'status' => 'error',
                'message' => 'فقط درخواست‌های با وضعیت 0 یا 1 قابل لغو هستند.'
            ], 403);
        }

        DB::beginTransaction();
        try {
            // ۱. لغو درخواست داروخانه
            DB::table('users_pharmacy_requests')
                ->where('id', $id)
                ->update([
                    'status' => 7,
                    'updated_at' => now()
                ]);

            // ۲. اگر سفارشی برای بیمار صادر شده بود (در حالت در انتظار پرداخت)، آن را لغو می‌کنیم
            if ($pharmacyRequest->status == 1) {
                DB::table('orders')
                    ->where('user_id', $pharmacyRequest->user_id)
                    ->where('reason_id', 6)
                    ->where('reason_ref', $id)
                    ->where('status', 1)
                    ->update([
                        'status' => 3, // 3: Cancelled
                        'cancelled_at' => now(),
                        'updated_at' => now()
                    ]);
            }

            DB::commit();

            return response()->json(['status' => 'success', 'message' => 'درخواست و فاکتور مربوطه با موفقیت لغو شد.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'خطا در لغو درخواست.'
            ], 500);
        }
    }

    // ---------- stats: آمار درخواست‌ها ----------
    public function stats(Request $request)
    {
        $pharmacyId = $this->getPharmacyId();

        $stats = [
            'total_requests'   => DB::table('users_pharmacy_requests')->where('pharmacy_id', $pharmacyId)->count(),
            'pending_requests' => DB::table('users_pharmacy_requests')->where('pharmacy_id', $pharmacyId)->where('status', 0)->count(),
        ];

        return response()->json(['status' => 'success', 'data' => $stats]);
    }

    // ---------- متدهای کمکی ----------
    private function updateRequestTotalPrice($requestId)
    {
        $total = DB::table('user_pharmacy_request_medicines')
            ->where('user_pharmacy_request_id', $requestId)
            ->sum(DB::raw('quantity * price'));

        DB::table('users_pharmacy_requests')
            ->where('id', $requestId)
            ->update([
                'total_price' => $total,
                'updated_at'  => now()
            ]);
    }
    /**
     * دانلود امن فایل نسخه برای پنل داروخانه
     */
    public function downloadPharmacyPrescription($requestId, $fileName)
    {
        $pharmacyId = auth()->id(); // شناسه داروخانه لاگین شده

        // ۱. بررسی دسترسی داروخانه به این درخواست
        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->where('id', $requestId)
            ->where(function($query) use ($pharmacyId) {
                // داروخانه فقط در صورتی مجاز است که درخواست به او اختصاص یافته باشد
                // یا درخواست هنوز آزاد باشد (منتظر پذیرش توسط داروخانه‌ها)
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

        // ۲. پاکسازی و ساخت مسیر فایل
        // حذف عبارت prescriptions/ در صورت ارسال اشتباه از سمت کلاینت تا مسیر تکراری نشود
        $cleanFileName = str_replace('prescriptions/', '', $fileName);
        $targetPath = 'prescriptions/' . $cleanFileName;

        // ۳. بررسی وجود فایل در دیسک امن
        if (!Storage::disk('local')->exists($targetPath)) {
            return response()->json([
                'success' => false,
                'message' => 'فایل نسخه یافت نشد.',
                'data'=>[$targetPath,$cleanFileName]
            ], 404);
        }

        // ۴. استخراج نام خالص برای هدر (جلوگیری از خطای اسلش که قبلا بررسی کردیم)
        $pureFileName = basename($targetPath);
        $mimeType = Storage::disk('local')->mimeType($targetPath);

        // ۵. ارسال فایل به مرورگر داروخانه
        return Storage::disk('local')->response($targetPath, $pureFileName, [
            'Content-Type' => $mimeType,
        ]);
    }

}
