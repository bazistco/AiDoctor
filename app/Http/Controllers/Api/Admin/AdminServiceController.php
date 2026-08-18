<?php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminServiceController extends Controller
{
    public function index()
    {
        $services = DB::table('service_types')->orderByDesc('id')->get();

        return response()->json([
            'status' => 'success',
            'data' => $services
        ]);
    }

    public function store(Request $request)
    {
        $id = DB::table('service_types')->insertGetId([
            'name' => $request->name,
            'service_key' => $request->service_key,
            'description' => $request->description,
            'status' => 1, // پیش‌فرض فعال
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'data' => ['id' => $id]
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        if ($request->has('status')) {
            DB::table('service_types')
                ->where('id', $id)
                ->update([
                    'status' => $request->status ? 1 : 0,
                    'updated_at' => now()
                ]);
        }

        return response()->json(['status' => 'success']);
    }

    public function destroy($id)
    {
        DB::table('service_types')->where('id', $id)->delete();
        return response()->json(['status' => 'success']);
    }
}
