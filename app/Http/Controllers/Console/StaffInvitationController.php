<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Mail\StaffInvitationMail;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Models\UserAgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StaffInvitationController extends Controller
{
    private const OPERATOR_ROLES = [
        'operator_owner',
        'operator_ops_coordinator',
        'operator_fleet_manager',
        'operator_finance_officer',
        'operator_data_manager',
    ];

    public function index(Request $request): JsonResponse
    {
        $scope = $this->agencyScope($request);

        $q = StaffInvitation::with('inviter:id,name')
            ->orderByDesc('created_at');

        if ($scope !== null) {
            $q->whereIn('agency_id', $scope);
        }

        if ($request->filled('agency_id')) {
            $q->where('agency_id', $request->input('agency_id'));
        }

        return response()->json($q->paginate($request->integer('per_page', 20)));
    }

    public function invite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id' => 'required|string|exists:agencies,agency_id',
            'email'     => 'required|email|max:255',
            'role'      => ['required', 'string', Rule::in(self::OPERATOR_ROLES)],
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);

        // Revoke any existing pending invitation for this email + agency
        StaffInvitation::where('agency_id', $data['agency_id'])
            ->where('email', $data['email'])
            ->whereNull('accepted_at')
            ->delete();

        $invitation = StaffInvitation::create([
            'agency_id'  => $data['agency_id'],
            'email'      => $data['email'],
            'role'       => $data['role'],
            'invited_by' => $request->user()->id,
            'token'      => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($data['email'])->send(new StaffInvitationMail($invitation));

        return response()->json(['message' => 'Invitation sent.', 'invitation' => $invitation], 201);
    }

    public function revoke(int $id): JsonResponse
    {
        $invitation = StaffInvitation::whereNull('accepted_at')->findOrFail($id);
        $invitation->delete();

        return response()->json(['message' => 'Invitation revoked.']);
    }

    // ── Public (no auth) ──────────────────────────────────────────────────────

    public function show(string $token): JsonResponse
    {
        $invitation = StaffInvitation::with('agency:agency_id,agency_name')->where('token', $token)->firstOrFail();

        if ($invitation->isExpired()) {
            return response()->json(['message' => 'This invitation has expired.'], 410);
        }

        if (!$invitation->isPending()) {
            return response()->json(['message' => 'This invitation has already been accepted.'], 409);
        }

        return response()->json([
            'agency_name' => $invitation->agency->agency_name,
            'email'       => $invitation->email,
            'role'        => $invitation->role,
            'expires_at'  => $invitation->expires_at,
        ]);
    }

    public function complete(Request $request, string $token): JsonResponse
    {
        $invitation = StaffInvitation::with('agency:agency_id,agency_name')->where('token', $token)->firstOrFail();

        if ($invitation->isExpired()) {
            return response()->json(['message' => 'This invitation has expired.'], 410);
        }

        if (!$invitation->isPending()) {
            return response()->json(['message' => 'This invitation has already been accepted.'], 409);
        }

        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = DB::transaction(function () use ($data, $invitation) {
            $user = User::create([
                'name'              => $data['name'],
                'email'             => $invitation->email,
                'password'          => Hash::make($data['password']),
                'role'              => 'user',
                'phone_verified_at' => now(),
            ]);

            $user->syncRoles([$invitation->role]);

            UserAgencyScope::create([
                'user_id'   => $user->id,
                'agency_id' => $invitation->agency_id,
            ]);

            $invitation->update(['accepted_at' => now()]);

            return $user;
        });

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json([
            'message' => 'Account created. You can now log in.',
            'email'   => $user->email,
        ], 201);
    }
}
