<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AddressController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $addresses = DB::table('addresses')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($address) {
                return [
                    'id' => (int) $address->id,
                    'title' => $address->title,
                    'address' => $address->address,
                    'lat' => $address->lat ? (float) $address->lat : null,
                    'lng' => $address->lng ? (float) $address->lng : null,
                    'created_at' => $address->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => ['addresses' => $addresses]
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'address' => 'required|string',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ], [
            'address.required' => 'نشانی الزامی است',
            'lat.between' => 'عرض جغرافیایی نامعتبر است',
            'lng.between' => 'طول جغرافیایی نامعتبر است',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در اعتبارسنجی',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = $request->user()->id;

        $addressId = DB::table('addresses')->insertGetId([
            'user_id' => $userId,
            'title' => $request->input('title'),
            'address' => $request->input('address'),
            'lat' => $request->input('lat'),
            'lng' => $request->input('lng'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $address = DB::table('addresses')->where('id', $addressId)->first();

        return response()->json([
            'success' => true,
            'message' => 'آدرس با موفقیت ثبت شد',
            'data' => [
                'address' => [
                    'id' => (int) $address->id,
                    'title' => $address->title,
                    'address' => $address->address,
                    'lat' => $address->lat ? (float) $address->lat : null,
                    'lng' => $address->lng ? (float) $address->lng : null,
                    'created_at' => $address->created_at,
                ]
            ]
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'address' => 'required|string',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ], [
            'address.required' => 'نشانی الزامی است',
            'lat.between' => 'عرض جغرافیایی نامعتبر است',
            'lng.between' => 'طول جغرافیایی نامعتبر است',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در اعتبارسنجی',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = $request->user()->id;

        $exists = DB::table('addresses')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->exists();

        if (!$exists) {
            return response()->json([
                'success' => false,
                'message' => 'آدرس یافت نشد یا متعلق به شما نیست'
            ], 404);
        }

        DB::table('addresses')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->update([
                'title' => $request->input('title'),
                'address' => $request->input('address'),
                'lat' => $request->input('lat'),
                'lng' => $request->input('lng'),
                'updated_at' => now(),
            ]);

        $address = DB::table('addresses')->where('id', $id)->first();

        return response()->json([
            'success' => true,
            'message' => 'آدرس با موفقیت ویرایش شد',
            'data' => [
                'address' => [
                    'id' => (int) $address->id,
                    'title' => $address->title,
                    'address' => $address->address,
                    'lat' => $address->lat ? (float) $address->lat : null,
                    'lng' => $address->lng ? (float) $address->lng : null,
                    'updated_at' => $address->updated_at,
                ]
            ]
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $userId = $request->user()->id;

        $exists = DB::table('addresses')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->exists();

        if (!$exists) {
            return response()->json([
                'success' => false,
                'message' => 'آدرس یافت نشد یا متعلق به شما نیست'
            ], 404);
        }

        DB::table('addresses')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'آدرس با موفقیت حذف شد'
        ]);
    }
}
