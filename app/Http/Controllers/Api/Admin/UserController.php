<?php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class UserController extends Controller
{
    private function baseQuery()
    {
        return DB::table('users as u')
            ->join('user_profiles as uf', 'uf.user_id', '=', 'u.id')
            ->join('cities as c', 'c.id', '=', 'uf.city')
            ->join('provinces as p', 'p.id', '=', 'uf.province')
            ->select(
                'u.id', 'u.name', 'u.email', 'u.phone',
                'u.role', 'u.status', 'u.is_verify', 'u.avatar',
                'u.created_at', 'u.updated_at',
                'c.name as city', 'p.name as province'
            );
    }

    private function formatUser(object $user): array
    {
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
        ];
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery()->whereNull('deleted_at');

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
            'name'      => 'sometimes|string|max:100',
            'email'     => 'sometimes|nullable|email',
            'phone'     => 'sometimes|nullable|string',
            'role'      => 'sometimes|string',
            'gender'    => 'sometimes|in:0,1',
            'is_verify' => 'sometimes|in:0,1',
            'status'    => 'sometimes|in:0,1',
            'avatar'    => 'sometimes|nullable|string',
        ]);

        DB::table('users')->where('id', $id)->update(array_merge($data, ['updated_at' => now()]));

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
}
