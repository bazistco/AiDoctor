<?php

namespace App\Http\Controllers\Api\Owner\Beauty;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class BeautyClinicServiceController extends Controller
{
    /**
     * ۱. لیست خدمات ثبت‌شده برای کلینیک زیبایی
     * GET /api/owner/beauty-clinic/services
     */
    public function index(Request $request)
    {
        $clinicId = $request->user()->id;

        $services = DB::table('beauty_clinic_services as bcs')
            ->join('beauty_service_packs as bsp', 'bsp.id', '=', 'bcs.service_pack_id')
            ->where('bcs.clinic_id', $clinicId)
            ->orderByDesc('bcs.id')
            ->select(
                'bcs.id',
                'bcs.service_pack_id',
                'bsp.name',
                'bsp.image',
                DB::raw('bcs.price / 10 as price'),
                'bcs.status'
            )
            ->get()
            ->map(function ($item) {
                $item->active = (int) $item->status === 1;
                $item->price  = (float) $item->price;
                if ($item->image && !str_starts_with($item->image, 'http')) {
                    $item->image = asset('storage/' . ltrim($item->image, '/'));
                }
                return $item;
            });

        return response()->json([
            'status' => true,
            'data'   => $services,
        ]);
    }

    /**
     * ۲. لیست خدمات عمومی موجود که هنوز به این کلینیک اضافه نشده‌اند
     * GET /api/owner/beauty-clinic/service-packs
     */
    public function availablePacks(Request $request)
    {
        $clinicId = $request->user()->id;

        $existingPackIds = DB::table('beauty_clinic_services')
            ->where('clinic_id', $clinicId)
            ->pluck('service_pack_id')
            ->toArray();

        $packs = DB::table('beauty_service_packs')
            ->where('status', 1)
            ->when(!empty($existingPackIds), function ($q) use ($existingPackIds) {
                $q->whereNotIn('id', $existingPackIds);
            })
            ->orderBy('name')
            ->select('id', 'name', 'image')
            ->get()
            ->map(function ($item) {
                if ($item->image && !str_starts_with($item->image, 'http')) {
                    $item->image = asset('storage/' . ltrim($item->image, '/'));
                }
                return $item;
            });

        return response()->json([
            'status' => true,
            'data'   => $packs,
        ]);
    }

    /**
     * ۳. افزودن خدمت (انتخاب از خدمات موجود یا ساخت خدمت جدید با عکس)
     * POST /api/owner/beauty-clinic/services
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mode'            => 'required|in:existing,new',
            'price'           => 'required|numeric|min:1000', // مبلغ به تومان
            'service_pack_id' => 'required_if:mode,existing|nullable|integer|exists:beauty_service_packs,id',
            'name'            => 'required_if:mode,new|nullable|string|max:255',
            'image'           => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $clinicId   = $request->user()->id;
        $priceRial  = (float) $request->input('price') * 10;
        $mode       = $request->input('mode');

        try {
            $serviceId = DB::transaction(function () use ($request, $clinicId, $priceRial, $mode) {
                if ($mode === 'existing') {
                    $packId = (int) $request->input('service_pack_id');

                    $exists = DB::table('beauty_clinic_services')
                        ->where('clinic_id', $clinicId)
                        ->where('service_pack_id', $packId)
                        ->exists();

                    if ($exists) {
                        throw new \RuntimeException('این خدمت قبلاً در لیست کلینیک شما ثبت شده است.', 422);
                    }
                } else {
                    $imagePath = null;
                    if ($request->hasFile('image')) {
                        $imagePath = $request->file('image')->store('beauty_services', 'public');
                    }

                    $packId = DB::table('beauty_service_packs')->insertGetId([
                        'name'       => trim($request->input('name')),
                        'image'      => $imagePath,
                        'status'     => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return DB::table('beauty_clinic_services')->insertGetId([
                    'clinic_id'       => $clinicId,
                    'service_pack_id' => $packId,
                    'price'           => $priceRial,
                    'status'          => 1,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            });

            return response()->json([
                'status'  => true,
                'message' => 'خدمت زیبایی با موفقیت ثبت شد.',
                'data'    => ['id' => $serviceId],
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 422);
        }
    }

    /**
     * ۴. ویرایش نرخ و (اختیاری) تصویر خدمت
     * POST /api/owner/beauty-clinic/services/{id}
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'price' => 'required|numeric|min:1000', // به تومان
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $clinicId = $request->user()->id;

        $service = DB::table('beauty_clinic_services')
            ->where('id', $id)
            ->where('clinic_id', $clinicId)
            ->first();

        if (!$service) {
            return response()->json([
                'status'  => false,
                'message' => 'خدمت مورد نظر یافت نشد.',
            ], 404);
        }

        DB::transaction(function () use ($request, $service) {
            DB::table('beauty_clinic_services')
                ->where('id', $service->id)
                ->update([
                    'price'      => (float) $request->input('price') * 10,
                    'updated_at' => now(),
                ]);

            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('beauty_services', 'public');
                DB::table('beauty_service_packs')
                    ->where('id', $service->service_pack_id)
                    ->update([
                        'image'      => $imagePath,
                        'updated_at' => now(),
                    ]);
            }
        });

        return response()->json([
            'status'  => true,
            'message' => 'تغییرات خدمت با موفقیت ذخیره شد.',
        ]);
    }

    /**
     * ۵. فعال / غیرفعال کردن خدمت در کلینیک
     * PUT /api/owner/beauty-clinic/services/{id}/toggle
     */
    public function toggleStatus(Request $request, $id)
    {
        $clinicId = $request->user()->id;

        $service = DB::table('beauty_clinic_services')
            ->where('id', $id)
            ->where('clinic_id', $clinicId)
            ->first();

        if (!$service) {
            return response()->json([
                'status'  => false,
                'message' => 'خدمت مورد نظر یافت نشد.',
            ], 404);
        }

        $newStatus = ((int) $service->status === 1) ? 0 : 1;

        DB::table('beauty_clinic_services')
            ->where('id', $id)
            ->update([
                'status'     => $newStatus,
                'updated_at' => now(),
            ]);

        return response()->json([
            'status'  => true,
            'message' => $newStatus === 1 ? 'خدمت فعال شد.' : 'خدمت غیرفعال شد.',
            'data'    => ['status' => $newStatus],
        ]);
    }
}
