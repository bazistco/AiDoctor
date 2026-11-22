<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\OrderService;
use App\Services\Payment\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BeautyClinicController extends Controller
{

    /**
     * ۱. دریافت جزئیات درخواست کلینیک زیبایی برای کاربر
     * GET /api/user/beauty-requests/{id}
     */
    public function getUserRequestDetail(Request $request, $id)
    {
        $userId = $request->user()->id;

        $req = DB::table('users_beauty_requests as ubr')
            ->leftJoin('users as u', 'u.id', '=', 'ubr.clinic_id')
            ->leftJoin('beauty_clinic_info as bci', 'bci.user_id', '=', 'ubr.clinic_id')
            ->leftJoin('beauty_clinic_slots as bcs', 'bcs.id', '=', 'ubr.slot_id')
            ->where('ubr.id', $id)
            ->where('ubr.user_id', $userId)
            ->select(
                'ubr.id',
                'ubr.status',
                DB::raw('ubr.total_price / 10 as total_price'),
                'ubr.created_at as request_date',
                'ubr.appointment_date',
                DB::raw("IF(bcs.id IS NOT NULL, CONCAT(LEFT(bcs.start_time, 5), ' تا ', LEFT(bcs.end_time, 5)), NULL) as slot_time"),
                DB::raw("COALESCE(u.name, 'کلینیک زیبایی') as clinic_name"),
                'bci.address as clinic_address',
                'bci.phone as clinic_phone'
            )
            ->first();

        if (!$req) {
            return response()->json([
                'success' => false,
                'message' => 'درخواست مورد نظر یافت نشد.',
            ], 404);
        }

        $services = DB::table('beauty_request_service_packs as brsp')
            ->join('beauty_clinic_services as bcs', 'bcs.id', '=', 'brsp.clinic_service_id')
            ->join('beauty_service_packs as bsp', 'bsp.id', '=', 'bcs.service_pack_id')
            ->where('brsp.beauty_request_id', $req->id)
            ->select(
                'brsp.id',
                'bsp.name as service_name',
                DB::raw('bcs.price / 10 as price')
            )
            ->get();

        $statusLabels = [
            0 => 'درخواست جدید',
            1 => 'در انتظار پرداخت',
            2 => 'در انتظار مراجعه',
            3 => 'در حال انجام',
            4 => 'تکمیل شده',
            5 => 'انجام شده',
            6 => 'لغو شده',
        ];

        return response()->json([
            'success' => true,
            'data'    => [
                'id'               => $req->id,
                'status'           => (int) $req->status,
                'status_label'     => $statusLabels[(int) $req->status] ?? 'نامشخص',
                'total_price'      => (float) $req->total_price,
                'request_date'     => $req->request_date,
                'appointment_date' => $req->appointment_date,
                'slot_time'        => $req->slot_time,
                'clinic_name'      => $req->clinic_name,
                'clinic_address'   => $req->clinic_address,
                'clinic_phone'     => $req->clinic_phone,
                'services'         => $services,
            ],
        ]);
    }

    /**
     * ۲. لغو دستی درخواست کلینیک زیبایی توسط کاربر (فقط قبل از پرداخت)
     * POST /api/user/beauty-requests/{id}/cancel
     */
    public function cancelUserRequest(Request $request, $id)
    {
        $userId = $request->user()->id;

        try {
            DB::transaction(function () use ($id, $userId) {
                $req = DB::table('users_beauty_requests')
                    ->where('id', $id)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();

                if (!$req) {
                    throw new \RuntimeException('درخواست مورد نظر یافت نشد.', 404);
                }

                if ((int) $req->status === 6) {
                    throw new \RuntimeException('این درخواست قبلاً لغو شده است.', 400);
                }

                if (!in_array((int) $req->status, [0, 1], true)) {
                    throw new \RuntimeException('پس از پرداخت، امکان لغو مستقیم درخواست وجود ندارد.', 422);
                }

                // تغییر وضعیت درخواست به لغو شده (6)
                DB::table('users_beauty_requests')
                    ->where('id', $req->id)
                    ->update([
                        'status'     => 6,
                        'updated_at' => now(),
                    ]);

                // آزادسازی فوری ظرفیت اسلات رزروشده
                if (!empty($req->slot_id)) {
                    DB::table('beauty_clinic_slots')
                        ->where('id', $req->slot_id)
                        ->where('booked_count', '>', 0)
                        ->decrement('booked_count');
                }

                // لغو سفارش معلق متناظر در جدول orders (reason_id = 10)
                DB::table('orders')
                    ->where('user_id', $userId)
                    ->where('reason_id', 10)
                    ->where('reason_ref', (string) $req->id)
                    ->where('status', 1)
                    ->update([
                        'status'       => 3, // FAILED / CANCELLED
                        'cancelled_at' => now(),
                        'updated_at'   => now(),
                    ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'درخواست شما با موفقیت لغو شد و ظرفیت نوبت آزاد گردید.',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        }
    }

    public function getServicePacks()
    {
        $items = DB::table('beauty_service_packs')
            ->join('beauty_clinic_services', 'beauty_clinic_services.service_pack_id', '=', 'beauty_service_packs.id')
            ->join('beauty_clinic_info', 'beauty_clinic_info.user_id', '=', 'beauty_clinic_services.clinic_id')
            ->join('users', 'users.id', '=', 'beauty_clinic_info.user_id')
            ->where('beauty_service_packs.status', 1)
            ->where('beauty_clinic_services.status', 1)
            ->where('beauty_clinic_info.status', 1)
            ->where('users.status', 1)
            ->groupBy(
                'beauty_service_packs.id',
                'beauty_service_packs.name',
                'beauty_service_packs.image',
                'beauty_service_packs.status'
            )
            ->select(
                'beauty_service_packs.id',
                'beauty_service_packs.name',
                'beauty_service_packs.image',
                'beauty_service_packs.status',
                DB::raw('MIN(beauty_clinic_services.price)/10 as min_price'),
                DB::raw('MAX(beauty_clinic_services.price)/10 as max_price')
            )
            ->orderBy('beauty_service_packs.name')
            ->get()->map(function ($item) {
                if (!empty($item->image) && !str_starts_with($item->image, 'http')) {
                    $item->image = 'https://api.mediraai.com/storage/' . ltrim($item->image, '/');
                }

                return $item;
            });

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function getCenters(Request $request)
    {
        $addressId = $request->query('user_address_id');
        $userAddress = null;

        if ($addressId) {
            $userAddress = DB::table('addresses')
                ->where('id', $addressId)
                ->where('user_id', $request->user()->id)
                ->first();
        }

        $query = DB::table('beauty_clinic_info')
            ->join('users', 'users.id', '=', 'beauty_clinic_info.user_id')
            ->where('users.role', 'beauty_clinic')
            ->where('users.status', 1)
            ->where('beauty_clinic_info.status', 1);

        $selects = [
            'beauty_clinic_info.user_id as id',
            'users.name',
            'beauty_clinic_info.slug',
            'beauty_clinic_info.address',
            'beauty_clinic_info.lat',
            'beauty_clinic_info.lng',
            'beauty_clinic_info.work_hours',
            'beauty_clinic_info.phone',
            'beauty_clinic_info.description',
            'beauty_clinic_info.image',
        ];

        if ($userAddress && !empty($userAddress->lat) && !empty($userAddress->lng)) {
            $lat = (float) $userAddress->lat;
            $lng = (float) $userAddress->lng;

            $selects[] = DB::raw("
                (6371 * acos(
                    cos(radians({$lat})) * cos(radians(beauty_clinic_info.lat)) *
                    cos(radians(beauty_clinic_info.lng) - radians({$lng})) +
                    sin(radians({$lat})) * sin(radians(beauty_clinic_info.lat))
                )) AS distance_km
            ");

            $query->select($selects)->orderByRaw('distance_km IS NULL, distance_km ASC');
        } else {
            $query->select($selects)->orderByDesc('beauty_clinic_info.created_at');
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function getCenterServices($clinicId)
    {
        $services = DB::table('beauty_clinic_services')
            ->join('beauty_service_packs', 'beauty_service_packs.id', '=', 'beauty_clinic_services.service_pack_id')
            ->where('beauty_clinic_services.clinic_id', $clinicId)
            ->where('beauty_clinic_services.status', 1)
            ->where('beauty_service_packs.status', 1)
            ->select(
                'beauty_service_packs.id',
                'beauty_service_packs.name',
                'beauty_service_packs.image',
                'beauty_service_packs.status',
                DB::raw('beauty_clinic_services.price/10 as price'),
                DB::raw('beauty_clinic_services.price/10 as min_price'),
                DB::raw('beauty_clinic_services.price/10 as max_price')
            )
            ->orderBy('beauty_service_packs.name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $services,
        ]);
    }

    public function getCenterSlots($clinicId)
    {
        $today = now()->toDateString();
        $nowTime = now()->format('H:i:s');

        $rows = DB::table('beauty_clinic_slots')
            ->where('clinic_id', $clinicId)
            ->where('status', 1)
            ->where(function ($q) use ($today, $nowTime) {
                $q->where('slot_date', '>', $today)
                    ->orWhere(function ($q2) use ($today, $nowTime) {
                        $q2->where('slot_date', '=', $today)
                            ->where('start_time', '>', $nowTime);
                    });
            })
            ->orderBy('slot_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            if (!isset($grouped[$row->slot_date])) {
                $diffDays = now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($row->slot_date)->startOfDay(), false);
                $label = match ($diffDays) {
                    0 => 'امروز',
                    1 => 'فردا',
                    2 => 'پس‌فردا',
                    default => $row->slot_date,
                };

                $grouped[$row->slot_date] = [
                    'date'  => $row->slot_date,
                    'label' => $label,
                    'slots' => [],
                ];
            }

            $start = substr($row->start_time, 0, 5);
            $end   = substr($row->end_time, 0, 5);

            $grouped[$row->slot_date]['slots'][] = [
                'id'         => $row->id,
                'start_time' => $start,
                'end_time'   => $end,
                'time'       => "{$start} تا {$end}",
                'is_full'    => $row->booked_count >= $row->capacity,
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => array_values($grouped),
        ]);
    }

    public function searchCenters(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_address_id' => 'nullable|exists:addresses,id',
            'service_pack_ids' => 'required|array|min:1',
            'service_pack_ids.*' => 'integer|exists:beauty_service_packs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $servicePackIds = array_values(array_unique($request->service_pack_ids));
        $userAddress = null;

        if ($request->filled('user_address_id')) {
            $userAddress = DB::table('addresses')
                ->where('id', $request->input('user_address_id'))
                ->where('user_id', $request->user()->id)
                ->first();
        }

        $query = DB::table('beauty_clinic_services')
            ->join('beauty_clinic_info', 'beauty_clinic_info.user_id', '=', 'beauty_clinic_services.clinic_id')
            ->join('users', 'users.id', '=', 'beauty_clinic_info.user_id')
            ->where('users.role', 'beauty_clinic')
            ->where('users.status', 1)
            ->where('beauty_clinic_info.status', 1)
            ->where('beauty_clinic_services.status', 1)
            ->whereIn('beauty_clinic_services.service_pack_id', $servicePackIds)
            ->groupBy(
                'beauty_clinic_info.user_id',
                'users.name',
                'beauty_clinic_info.slug',
                'beauty_clinic_info.address',
                'beauty_clinic_info.lat',
                'beauty_clinic_info.lng',
                'beauty_clinic_info.work_hours',
                'beauty_clinic_info.image'
            )
            ->havingRaw('COUNT(DISTINCT beauty_clinic_services.service_pack_id) = ?', [count($servicePackIds)]);

        $selects = [
            'beauty_clinic_info.user_id as id',
            'users.name',
            'beauty_clinic_info.slug',
            'beauty_clinic_info.address',
            'beauty_clinic_info.lat',
            'beauty_clinic_info.lng',
            'beauty_clinic_info.work_hours',
            'beauty_clinic_info.image',
            DB::raw('SUM(beauty_clinic_services.price)/10 as total_price'),
        ];

        if ($userAddress && !empty($userAddress->lat) && !empty($userAddress->lng)) {
            $lat = (float) $userAddress->lat;
            $lng = (float) $userAddress->lng;

            $selects[] = DB::raw("
                (6371 * acos(
                    cos(radians({$lat})) * cos(radians(beauty_clinic_info.lat)) *
                    cos(radians(beauty_clinic_info.lng) - radians({$lng})) +
                    sin(radians({$lat})) * sin(radians(beauty_clinic_info.lat))
                )) AS distance_km
            ");

            $query->select($selects)->orderByRaw('distance_km IS NULL, distance_km ASC');
        } else {
            $query->select($selects)->orderBy('total_price');
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    private function isClinicActive(int $clinicId): bool
    {
        return DB::table('users as u')
            ->join('beauty_clinic_info as bci', 'u.id', '=', 'bci.user_id')
            ->where('u.role', 'beauty_clinic')
            ->where('u.status', 1)
            ->where('bci.status', 1)
            ->where('u.id', $clinicId)
            ->exists();
    }

    public function storeRequest(Request $request, OrderService $orderService, PaymentService $paymentService)
    {
        $validator = Validator::make($request->all(), [
            'request_type_id' => 'required|integer|in:1,2,3,4',
            'visit_type' => 'required|integer|in:0,1',
            'user_address_id' => 'required|integer|exists:addresses,id',

            'clinic_id' => 'required_if:request_type_id,1,4|nullable|integer',
            'slot_id' => 'required_if:request_type_id,1,4|nullable|integer|exists:beauty_clinic_slots,id',
            'service_pack_ids' => 'required_if:request_type_id,1,4|array|min:1',
            'service_pack_ids.*' => 'integer|exists:beauty_service_packs,id',

            'digital_code' => 'required_if:request_type_id,2|nullable|string|max:100',

            'files' => 'required_if:request_type_id,3|array|min:1',
            'files.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $hasPending = DB::table('users_beauty_requests')
            ->where('user_id', $request->user()->id)
            ->whereIn('status', [0, 1])
            ->exists();

        if ($hasPending) {
            return response()->json([
                'success' => false,
                'message' => 'شما یک درخواست در انتظار پرداخت دارید. لطفاً ابتدا آن را تعیین تکلیف یا پرداخت نمایید.',
            ], 400);
        }

        $requestTypeId = (int) $request->request_type_id;
        $isDirectBooking = in_array($requestTypeId, [1, 4], true);

        if ($isDirectBooking && !$this->isClinicActive((int) $request->clinic_id)) {
            return response()->json([
                'success' => false,
                'message' => 'کلینیک زیبایی مورد نظر در حال حاضر غیرفعال است و امکان رزرو نوبت وجود ندارد.',
            ], 422);
        }

        try {
            $user = $request->user();

            $result = DB::transaction(function () use ($request, $user, $requestTypeId, $isDirectBooking, $orderService, $paymentService) {
                $prescriptionDetails = [
                    'code' => '',
                    'files' => [],
                ];

                if ($requestTypeId === 2) {
                    $prescriptionDetails['code'] = $request->digital_code;
                }

                if ($requestTypeId === 3 && $request->hasFile('files')) {
                    foreach ($request->file('files') as $file) {
                        $path = $file->store('prescriptions', 'local');
                        $prescriptionDetails['files'][] = $path;
                    }
                }

                $prescriptionTypeId = ($requestTypeId === 4) ? 1 : $requestTypeId;

                $prescriptionId = DB::table('users_prescriptions')->insertGetId([
                    'user_id' => $user->id,
                    'prescription_type_id' => $prescriptionTypeId,
                    'status' => 1,
                    'details' => json_encode($prescriptionDetails),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $clinicId = null;
                $slotId = null;
                $appointmentDate = null;
                $totalPrice = 0;
                $paymentUrl = null;
                $clinicServices = collect();

                if ($isDirectBooking) {
                    $clinicId = (int) $request->clinic_id;
                    $slotId = (int) $request->slot_id;
                    $servicePackIds = array_values(array_unique($request->service_pack_ids));

                    $slot = DB::table('beauty_clinic_slots')
                        ->where('id', $slotId)
                        ->where('clinic_id', $clinicId)
                        ->where('status', 1)
                        ->lockForUpdate()
                        ->first();

                    if (!$slot || $slot->booked_count >= $slot->capacity) {
                        throw new \RuntimeException('این نوبت توسط کاربر دیگری در حال رزرو است یا تکمیل شده است.');
                    }

                    DB::table('beauty_clinic_slots')
                        ->where('id', $slotId)
                        ->increment('booked_count');

                    $clinicServices = DB::table('beauty_clinic_services')
                        ->join('beauty_clinic_info', 'beauty_clinic_info.user_id', '=', 'beauty_clinic_services.clinic_id')
                        ->where('beauty_clinic_info.user_id', $clinicId)
                        ->where('beauty_clinic_info.status', 1)
                        ->where('beauty_clinic_services.status', 1)
                        ->whereIn('beauty_clinic_services.service_pack_id', $servicePackIds)
                        ->select('beauty_clinic_services.id', 'beauty_clinic_services.service_pack_id', 'beauty_clinic_services.price')
                        ->get();

                    if ($clinicServices->count() !== count($servicePackIds)) {
                        throw new \RuntimeException('برخی خدمات انتخابی در این کلینیک موجود نیست.');
                    }

                    $totalPrice = (float) $clinicServices->sum('price'); // به ریال برای درگاه پرداخت
                    $appointmentDate = $slot->slot_date;

                    DB::table('beauty_clinic_slots')
                        ->where('id', $slotId)
                        ->increment('booked_count');
                }

                $status = $isDirectBooking ? 1 : 0;

                $beautyRequestId = DB::table('users_beauty_requests')->insertGetId([
                    'address_id' => $request->user_address_id,
                    'user_id' => $user->id,
                    'clinic_id' => $clinicId,
                    'slot_id' => $slotId,
                    'visit_type' => $request->visit_type,
                    'request_type_id' => $requestTypeId,
                    'user_prescription_id' => $prescriptionId,
                    'appointment_date' => $appointmentDate,
                    'status' => $status,
                    'total_price' => $totalPrice,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($isDirectBooking) {
                    foreach ($clinicServices as $srv) {
                        DB::table('beauty_request_service_packs')->insert([
                            'beauty_request_id' => $beautyRequestId,
                            'clinic_service_id' => $srv->id,
                            'status' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    // ایجاد سفارش و لینک پرداخت (reasonId را مطابق جدول order_reasons خود تنظیم کنید)
                    $orderResult = $orderService->createOrReuse(
                        userId: $user->id,
                        reasonId: 10,
                        reasonRef: $beautyRequestId,
                        amount: $totalPrice,
                        description: "پرداخت فاکتور کلینیک زیبایی - درخواست #{$beautyRequestId}",
                        providerId: $clinicId
                    );

                    $callbackUrl = 'https://mediraai.com/api/pg/call_back';

                    $paymentResult = $paymentService->initiate(
                        orderId: $orderResult['order_id'],
                        userId: $user->id,
                        callbackUrl: $callbackUrl
                    );

                    $paymentUrl = $paymentResult['payment_url'];
                }

                return [
                    'request_id' => $beautyRequestId,
                    'prescription_id' => $prescriptionId,
                    'clinic_id' => $clinicId,
                    'slot_id' => $slotId,
                    'appointment_date' => $appointmentDate,
                    'total_price' => $totalPrice,
                    'status' => $status,
                    'payment_url' => $paymentUrl,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => $isDirectBooking ? 'در حال انتقال به درگاه پرداخت...' : 'درخواست با موفقیت ثبت شد.',
                'data' => $result,
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطای سیستمی در پردازش درخواست رخ داده است.',
            ], 500);
        }
    }

    public function getUserRequests(Request $request)
    {
        $items = DB::table('users_beauty_requests')
            ->leftJoin('users', 'users.id', '=', 'users_beauty_requests.clinic_id')
            ->leftJoin('beauty_clinic_slots', 'beauty_clinic_slots.id', '=', 'users_beauty_requests.slot_id')
            ->where('users_beauty_requests.user_id', $request->user()->id)
            ->orderByDesc('users_beauty_requests.id')
            ->select(
                'users_beauty_requests.id',
                'users_beauty_requests.clinic_id',
                'users_beauty_requests.visit_type',
                'users_beauty_requests.request_type_id',
                'users_beauty_requests.appointment_date',
                DB::raw("CONCAT(LEFT(beauty_clinic_slots.start_time, 5), ' تا ', LEFT(beauty_clinic_slots.end_time, 5)) as slot_time"),
                'users_beauty_requests.status',
                DB::raw('users_beauty_requests.total_price/10 as total_price'),
                'users_beauty_requests.created_at',
                'users.name as clinic_name'
            )
            ->get();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }
}
