<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::where('organization_id', $request->user()->organization_id)
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => $this->payload($u));

        return response()->json(['users' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'password' => 'required|string|min:8',
            'role' => 'required|in:admin,manager,staff',
        ]);

        $user = User::create($data + ['organization_id' => $request->user()->organization_id]);

        return response()->json(['user' => $this->payload($user)], 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->assertSameOrg($request, $user);

        return response()->json(['user' => $this->payload($user)]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->assertSameOrg($request, $user);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email',
            'password' => 'sometimes|string|min:8',
        ]);

        if (isset($data['password']) && $data['password']) {
            $user->password = $data['password'];
            unset($data['password']);
        }

        $user->fill($data)->save();

        return response()->json(['user' => $this->payload($user)]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->assertSameOrg($request, $user);

        if ($user->id === $request->user()->id) {
            return response()->json(['error' => 'You cannot delete your own account.'], 422);
        }

        $user->delete();

        return response()->json(['ok' => true]);
    }

    public function role(Request $request, User $user): JsonResponse
    {
        $this->assertSameOrg($request, $user);

        $data = $request->validate(['role' => 'required|in:admin,manager,staff']);
        $user->update(['role' => $data['role']]);

        return response()->json(['user' => $this->payload($user)]);
    }

    public function activate(User $user): JsonResponse
    {
        $user->update(['is_active' => true]);

        return response()->json(['ok' => true]);
    }

    public function deactivate(User $user): JsonResponse
    {
        $user->update(['is_active' => false]);

        return response()->json(['ok' => true]);
    }

    private function assertSameOrg(Request $request, User $user): void
    {
        abort_if($user->organization_id !== $request->user()->organization_id, 404);
    }

    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'last_login_at' => $user->last_login_at,
        ];
    }
}
