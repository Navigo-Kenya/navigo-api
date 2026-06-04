<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserAgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class AccessController extends Controller
{
    // ── Roles ────────────────────────────────────────────────────────────────

    public function listRoles(): JsonResponse
    {
        $userCounts = DB::table('model_has_roles')
            ->select('role_id', DB::raw('count(*) as cnt'))
            ->groupBy('role_id')
            ->pluck('cnt', 'role_id');

        $roles = Role::with('permissions')->get()
            ->map(fn(Role $r) => [
                'id'           => $r->id,
                'name'         => $r->name,
                'permissions'  => $r->permissions->pluck('name'),
                'users_count'  => (int) ($userCounts[$r->id] ?? 0),
                'is_protected' => $r->name === 'superadmin',
            ]);

        return response()->json(['data' => $roles]);
    }

    public function showRole(Role $role): JsonResponse
    {
        return response()->json([
            'id'           => $role->id,
            'name'         => $role->name,
            'permissions'  => $role->permissions->pluck('name'),
            'is_protected' => $role->name === 'superadmin',
        ]);
    }

    public function updateRolePermissions(Request $request, Role $role): JsonResponse
    {
        if ($role->name === 'superadmin') {
            return response()->json(['message' => 'The superadmin role cannot be modified.'], 403);
        }

        $data = $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $role->syncPermissions($data['permissions']);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json([
            'permissions' => $role->fresh('permissions')->permissions->pluck('name'),
        ]);
    }

    // ── Console users ─────────────────────────────────────────────────────────

    public function listUsers(Request $request): JsonResponse
    {
        $query = User::with(['roles', 'agencyScopes'])
            ->whereHas('roles')
            ->latest();

        if ($request->filled('role')) {
            $query->whereHas('roles', fn($q) => $q->where('name', $request->role));
        }

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(fn($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }

        $users = $query->paginate(25)->through(fn(User $u) => $this->formatUser($u));

        return response()->json($users);
    }

    public function createUser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role'     => ['required', 'string', Rule::exists('roles', 'name')],
        ]);

        $this->denyIfTargetingHigherRole($request->user(), $data['role']);

        $user = User::create([
            'name'              => $data['name'],
            'email'             => $data['email'],
            'password'          => Hash::make($data['password']),
            'role'              => $this->legacyRoleFor($data['role']),
            'phone_verified_at' => now(),
        ]);

        $user->syncRoles([$data['role']]);

        return response()->json(['user' => $this->formatUser($user->load('roles', 'agencyScopes'))], 201);
    }

    public function showUser(User $user): JsonResponse
    {
        return response()->json(['user' => $this->formatUser($user->load('roles', 'agencyScopes'))]);
    }

    public function updateUserRole(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', Rule::exists('roles', 'name')],
        ]);

        $this->denyIfTargetingHigherRole($request->user(), $data['role']);

        // Prevent demotion of a superadmin by anyone other than themselves
        if ($user->hasRole('superadmin') && $request->user()->id !== $user->id) {
            return response()->json(['message' => 'Cannot modify a superadmin account.'], 403);
        }

        $user->syncRoles([$data['role']]);
        $user->update(['role' => $this->legacyRoleFor($data['role'])]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['user' => $this->formatUser($user->fresh(['roles', 'agencyScopes']))]);
    }

    public function updateUserPermissions(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        // Individual permission overrides (in addition to role permissions)
        $user->syncPermissions($data['permissions']);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json([
            'effective_permissions' => $user->fresh()->getEffectivePermissions(),
        ]);
    }

    public function updateUserAgencies(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'agency_ids'   => 'required|array',
            'agency_ids.*' => 'string|exists:agencies,agency_id',
        ]);

        UserAgencyScope::where('user_id', $user->id)->delete();

        $rows = array_map(
            fn(string $id) => ['user_id' => $user->id, 'agency_id' => $id],
            $data['agency_ids']
        );
        UserAgencyScope::insert($rows);

        return response()->json([
            'agency_scopes' => $user->agencyScopes()->pluck('agency_id'),
        ]);
    }

    public function revokeAccess(User $user): JsonResponse
    {
        if ($user->hasRole('superadmin')) {
            return response()->json(['message' => 'Cannot revoke a superadmin account.'], 403);
        }

        $user->syncRoles([]);
        $user->syncPermissions([]);
        UserAgencyScope::where('user_id', $user->id)->delete();
        $user->update(['role' => 'user']);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['message' => 'Console access revoked.']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatUser(User $user): array
    {
        return [
            'id'            => $user->id,
            'name'          => $user->name,
            'email'         => $user->email,
            'avatar'        => $user->avatar,
            'console_role'  => $user->roles->first()?->name ?? $user->role,
            'agency_scopes' => $user->agencyScopes->pluck('agency_id'),
            'permissions'   => $user->getEffectivePermissions(),
            'is_banned'     => $user->isBanned(),
            'last_seen_at'  => $user->last_seen_at ?? null,
            'created_at'    => $user->created_at,
        ];
    }

    private function denyIfTargetingHigherRole(User $actor, string $targetRole): void
    {
        // Only superadmin can assign superadmin or hopln_admin
        $restricted = ['superadmin', 'hopln_admin'];
        if (\in_array($targetRole, $restricted, true) && !$actor->hasRole('superadmin')) {
            abort(403, 'Insufficient permissions to assign this role.');
        }
    }

    private function legacyRoleFor(string $spatieRole): string
    {
        return match($spatieRole) {
            'superadmin'  => 'superadmin',
            'hopln_admin' => 'admin',
            'hopln_staff' => 'admin',
            'moderator'   => 'moderator',
            default       => 'user',
        };
    }
}
