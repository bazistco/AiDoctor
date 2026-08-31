<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LabController extends Controller
{
    public function getPrescriptionTypes()
    {
        $items = DB::table('prescription_types')
            ->where('status', 1)
            ->select('id', 'name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function getTestPacks()
    {
        $items = DB::table('test_packs')
            ->join('labs_tests', 'labs_tests.test_pack_id', '=', 'test_packs.id')
            ->join('labs_info', 'labs_info.user_id', '=', 'labs_tests.lab_id')
            ->where('test_packs.status', 1)
            ->where('labs_tests.status', 1)
            ->where('labs_info.status', 1)
            ->groupBy('test_packs.id', 'test_packs.name', 'test_packs.status')
            ->select(
                'test_packs.id',
                'test_packs.name',
                'test_packs.status',
                DB::raw('MIN(labs_tests.price) as min_price'),
                DB::raw('MAX(labs_tests.price) as max_price')
            )
            ->orderBy('test_packs.name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function searchCenters(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'test_pack_ids' => 'required|array|min:1',
            'test_pack_ids.*' => 'integer|exists:test_packs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $testPackIds = array_values(array_unique($request->test_pack_ids));

        $labs = DB::table('labs_tests')
            ->join('labs_info', 'labs_info.user_id', '=', 'labs_tests.lab_id')
            ->join('users', 'users.id', '=', 'labs_info.user_id')
            ->where('labs_info.status', 1)
            ->where('labs_tests.status', 1)
            ->whereIn('labs_tests.test_pack_id', $testPackIds)
            ->groupBy(
                'labs_info.user_id',
                'users.name',
                'labs_info.address',
                'labs_info.lat',
                'labs_info.lng',
                'labs_info.image'
            )
            ->havingRaw('COUNT(DISTINCT labs_tests.test_pack_id) = ?', [count($testPackIds)])
            ->select(
                'labs_info.user_id as id',
                'users.name',
                'labs_info.address',
                'labs_info.lat',
                'labs_info.lng',
                'labs_info.image',
                DB::raw('SUM(labs_tests.price) as total_price')
            )
            ->orderBy('total_price')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $labs,
        ]);
    }

    public function storeRequest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'request_type_id' => 'required|integer|exists:lab_request_types,id',
            'visit_type' => 'required|integer|in:0,1',
            'user_address_id'=>'required|integer|exists:addresses,id',
            'lab_id' => 'required_if:request_type_id,1|nullable|integer',
            'test_pack_ids' => 'required_if:request_type_id,1|array|min:1',
            'test_pack_ids.*' => 'integer|exists:test_packs,id',

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

        try {
            $user = $request->user();

            $result = DB::transaction(function () use ($request, $user) {
                $requestTypeId = (int) $request->request_type_id;

                $prescriptionDetails = [
                    'code' => '',
                    'files' => [],
                ];

                if ($requestTypeId === 2) {
                    $prescriptionDetails['code'] = $request->digital_code;
                }

                if ($requestTypeId === 3 && $request->hasFile('files')) {
                    foreach ($request->file('files') as $file) {
                        $path = $file->store('prescriptions', 'public');
                        $prescriptionDetails['files'][] = '/storage/' . $path;
                    }
                }

                $prescriptionId = DB::table('users_prescriptions')->insertGetId([
                    'user_id' => $user->id,
                    'prescription_type_id' => $requestTypeId,
                    'status' => 1,
                    'details' => json_encode($prescriptionDetails),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $labId = null;
                $totalPrice = 0;

                if ($requestTypeId === 1) {
                    $labId = (int) $request->lab_id;
                    $testPackIds = array_values(array_unique($request->test_pack_ids));

                    $labTests = DB::table('labs_tests')
                        ->join('labs_info', 'labs_info.user_id', '=', 'labs_tests.lab_id')
                        ->where('labs_info.user_id', $labId)
                        ->where('labs_info.status', 1)
                        ->where('labs_tests.status', 1)
                        ->whereIn('labs_tests.test_pack_id', $testPackIds)
                        ->select('labs_tests.id', 'labs_tests.test_pack_id', 'labs_tests.price')
                        ->get();

                    if ($labTests->count() !== count($testPackIds)) {
                        throw new \RuntimeException('برخی تست های انتخابی در این آزمایشگاه موجود نیست.');
                    }

                    $totalPrice = (float) $labTests->sum('price');
                }

                $labRequestId = DB::table('users_labs_requests')->insertGetId([
                    'address_id'=>@$request->user_address_id,
                    'user_id' => $user->id,
                    'lab_id' => $labId,
                    'visit_type' => $request->visit_type,
                    'request_type_id' => $requestTypeId,
                    'user_prescription_id' => $prescriptionId,
                    'status' => isset($labId)?2:0,
                    'total_price' => $totalPrice,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($requestTypeId === 1) {
                    foreach ($labTests as $labTest) {
                        DB::table('lab_request_test_packs')->insert([
                            'lab_request_id' => $labRequestId,
                            'lab_test_id' => $labTest->id,
                            'status' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                return [
                    'request_id' => $labRequestId,
                    'prescription_id' => $prescriptionId,
                    'lab_id' => $labId,
                    'total_price' => $totalPrice,
                    'status' => 0,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'درخواست با موفقیت ثبت شد.',
                'data' => $result,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getUserRequests(Request $request)
    {
        $items = DB::table('users_labs_requests')
            ->leftJoin('users', 'users.id', '=', 'users_labs_requests.lab_id')
            ->join('lab_request_types', 'lab_request_types.id', '=', 'users_labs_requests.request_type_id')
            ->where('users_labs_requests.user_id', $request->user()->id)
            ->orderByDesc('users_labs_requests.id')
            ->select(
                'users_labs_requests.id',
                'users_labs_requests.lab_id',
                'users_labs_requests.visit_type',
                'users_labs_requests.request_type_id',
                'users_labs_requests.status',
                'users_labs_requests.total_price',
                'users_labs_requests.created_at',
                'users.name as lab_name',
                'lab_request_types.name as request_type_name'
            )
            ->get();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function getUserRequestDetail(Request $request, $id)
    {
        $item = DB::table('users_labs_requests')
            ->leftJoin('users', 'users.id', '=', 'users_labs_requests.lab_id')
            ->join('lab_request_types', 'lab_request_types.id', '=', 'users_labs_requests.request_type_id')
            ->join('users_prescriptions', 'users_prescriptions.id', '=', 'users_labs_requests.user_prescription_id')
            ->where('users_labs_requests.user_id', $request->user()->id)
            ->where('users_labs_requests.id', $id)
            ->select(
                'users_labs_requests.*',
                'users.name as lab_name',
                'lab_request_types.name as request_type_name',
                'users_prescriptions.details as prescription_details',
                'users_prescriptions.prescription_type_id'
            )
            ->first();

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'درخواست یافت نشد.',
            ], 404);
        }

        $tests = DB::table('lab_request_test_packs')
            ->join('labs_tests', 'labs_tests.id', '=', 'lab_request_test_packs.lab_test_id')
            ->join('test_packs', 'test_packs.id', '=', 'labs_tests.test_pack_id')
            ->where('lab_request_test_packs.lab_request_id', $id)
            ->select(
                'lab_request_test_packs.id',
                'labs_tests.id as lab_test_id',
                'test_packs.id as test_pack_id',
                'test_packs.name as test_pack_name',
                'labs_tests.price'
            )
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'request' => $item,
                'prescription_details' => json_decode($item->prescription_details, true),
                'tests' => $tests,
            ],
        ]);
    }
}
