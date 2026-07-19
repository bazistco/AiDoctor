<?php

namespace App\Http\Controllers\Api\Owner\Pharmacies;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PharmacyRequestController extends Controller
{
    protected function getPharmacyId()
    {
        return auth()->id();
    }


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

        if ($request->has('status') && $request->input('status') !== '') {
            $query->where('users_pharmacy_requests.status', $request->input('status'));
        }

        $requests = $query->get();

        return response()->json(['status' => 'success', 'data' => $requests]);
    }

    public function show($id)
    {
        $pharmacyId = $this->getPharmacyId();

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->leftJoin('users', 'users_pharmacy_requests.user_id', '=', 'users.id')
            ->where('users_pharmacy_requests.id', $id)
            ->where(function ($query) use ($pharmacyId) {
                $query->where('users_pharmacy_requests.pharmacy_id', $pharmacyId)
                    ->orWhereNull('users_pharmacy_requests.pharmacy_id');
            })
            ->select(
                'users_pharmacy_requests.*',
                'users.name as user_name',
                'users.phone as user_mobile',
                'users.national_code as user_national_code'
            )
            ->first();

        if (!$pharmacyRequest) {
            return response()->json(['status' => 'error', 'message' => 'درخواست یافت نشد.'], 404);
        }

        $items = DB::table('user_pharmacy_request_medicines as uprm')
            ->join('pharmacy_medicines as pm', 'uprm.pharmacy_medicine_id', '=', 'pm.id')
            ->join('medicines', 'pm.medicine_id', '=', 'medicines.id')
            ->leftJoin('medicine_types', 'pm.medicine_type_id', '=', 'medicine_types.id')
            ->where('uprm.user_pharmacy_request_id', $id)
            ->select(
                'uprm.id',
                'pm.medicine_id',                          // alias as medicine_id for frontend
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
            'data' => [
                'request' => $pharmacyRequest,
                'items'   => $items,
            ]
        ]);
    }

    public function addItem(Request $request, $id)
    {
        $pharmacyId = $this->getPharmacyId();

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->where('status', 0)
            ->first();

        if (!$pharmacyRequest) {
            return response()->json(['status' => 'error', 'message' => 'دسترسی غیرمجاز یا وضعیت نامعتبر.'], 403);
        }

        $medicineId = $request->input('medicine_id');
        $qty        = (int) $request->input('qty', 1);
        $price      = (float) $request->input('price', 0);
        $unit       = $request->input('unit', 'عدد');

        // Find or create the pharmacy_medicine record
        $pharmacyMedicine = DB::table('pharmacy_medicines')
            ->where('pharmacy_id', $pharmacyId)
            ->where('medicine_id', $medicineId)
            ->first();

        if (!$pharmacyMedicine) {
            $pharmacyMedicineId = DB::table('pharmacy_medicines')->insertGetId([
                'pharmacy_id'      => $pharmacyId,
                'medicine_id'      => $medicineId,
                'medicine_type_id' => 1,
                'unit'             => $unit,
                'price_per_unit'   => $price,
                'status'           => 1,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        } else {
            $pharmacyMedicineId = $pharmacyMedicine->id;
            DB::table('pharmacy_medicines')
                ->where('id', $pharmacyMedicineId)
                ->update([
                    'price_per_unit' => $price,
                    'unit'           => $unit,
                    'updated_at'     => now()
                ]);
        }

        // Insert the request item using pharmacy_medicine_id and quantity
        DB::table('user_pharmacy_request_medicines')->insert([
            'user_pharmacy_request_id' => $id,
            'pharmacy_medicine_id'     => $pharmacyMedicineId,
            'quantity'                 => $qty,
            'price'                    => $price,
            // total_price is not stored – computed when needed
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $this->updateRequestTotalPrice($id);

        return response()->json(['status' => 'success', 'message' => 'دارو با موفقیت اضافه شد.']);
    }


    public function stats(Request $request)
    {
        $pharmacyId = $this->getPharmacyId();

        $stats = [
            'total_requests' => DB::table('users_pharmacy_requests')->where('pharmacy_id', $pharmacyId)->count(),
            'pending_requests' => DB::table('users_pharmacy_requests')->where('pharmacy_id', $pharmacyId)->where('status', 0)->count(),
        ];

        return response()->json(['status' => 'success', 'data' => $stats]);
    }



    public function acceptRequest($id)
    {
        $pharmacyId = $this->getPharmacyId();

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->whereNull('pharmacy_id')
            ->first();

        if (!$pharmacyRequest) {
            return response()->json(['status' => 'error', 'message' => 'این درخواست قبلاً توسط داروخانه دیگری برداشته شده یا وجود ندارد.'], 400);
        }

        DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->update([
                'pharmacy_id' => $pharmacyId,
                'updated_at' => now()
            ]);

        return response()->json(['status' => 'success', 'message' => 'درخواست با موفقیت برای شما رزرو شد. حالا می‌توانید داروها را اضافه کنید.']);
    }

    public function releaseRequest($id)
    {
        $pharmacyId = $this->getPharmacyId();

        $pharmacyRequest = DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->first();

        if (!$pharmacyRequest) {
            return response()->json(['status' => 'error', 'message' => 'درخواست یافت نشد یا متعلق به شما نیست.'], 404);
        }

        DB::table('user_pharmacy_request_medicines')
            ->where('user_pharmacy_request_id', $id)
            ->delete();

        DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->update([
                'pharmacy_id' => null,
                'total_price' => 0,
                'updated_at' => now()
            ]);

        return response()->json(['status' => 'success', 'message' => 'درخواست رها شد و به لیست درخواست‌های آزاد بازگشت.']);
    }

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



    public function removeItem($id, $itemId)
    {
        DB::table('user_pharmacy_request_medicines')
            ->where('id', $itemId)
            ->where('user_pharmacy_request_id', $id)
            ->delete();

        $this->updateRequestTotalPrice($id);

        return response()->json(['status' => 'success', 'message' => 'دارو با موفقیت حذف شد.']);
    }

    public function updateStatus(Request $request, $id)
    {
        $pharmacyId = $this->getPharmacyId();
        $status = $request->input('status');

        DB::table('users_pharmacy_requests')
            ->where('id', $id)
            ->where('pharmacy_id', $pharmacyId)
            ->update([
                'status' => $status,
                'updated_at' => now()
            ]);

        return response()->json(['status' => 'success', 'message' => 'وضعیت با موفقیت تغییر یافت.']);
    }

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
}
