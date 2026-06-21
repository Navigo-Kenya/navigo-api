<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Route;
use App\Models\SplitConfig;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Models\Wallet;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsoleAgencyController extends Controller
{
    public function __construct(private StorageService $storage) {}

    public function index(): JsonResponse
    {
        return response()->json(Agency::withCount('routes')->orderBy('agency_name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'         => 'required|string|unique:agencies,agency_id',
            'agency_name'       => 'required|string|max:200',
            'agency_url'        => 'required|string|url|max:255',
            'agency_timezone'   => 'required|string|max:50',
            'agency_lang'       => 'nullable|string|max:10',
            'agency_phone'      => 'nullable|string|max:50',
            'agency_email'      => 'nullable|email|max:200',
            'type'              => 'nullable|string|in:authority,operator',
            'reg_number'        => 'nullable|string|max:50',
            'region'            => 'nullable|string|max:100',
            'terminus_location' => 'nullable|string',
        ]);

        $agency = Agency::create($data);

        Wallet::firstOrCreate(
            ['entity_type' => 'agency', 'entity_id' => $agency->agency_id],
            ['balance' => 0, 'currency' => 'KES'],
        );

        return response()->json($agency, 201);
    }

    public function uploadLogo(Request $request, string $id): JsonResponse
    {
        $agency = Agency::findOrFail($id);
        $this->assertAgencyAllowed($request, $agency->agency_id);

        $request->validate(['logo' => 'required|image|max:4096']);

        $this->storage->delete($agency->getRawOriginal('logo_url'));
        $url = $this->storage->upload($request->file('logo'), "agency-logos/{$agency->agency_id}");

        $agency->update(['logo_url' => $url]);

        return response()->json(['logo_url' => $url]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $agency = Agency::findOrFail($id);
        $this->assertAgencyAllowed($request, $agency->agency_id);

        $data = $request->validate([
            'agency_name'       => 'sometimes|string|max:200',
            'agency_url'        => 'sometimes|string|url|max:255',
            'agency_timezone'   => 'sometimes|string|max:50',
            'agency_lang'       => 'nullable|string|max:10',
            'agency_phone'      => 'nullable|string|max:50',
            'agency_email'      => 'nullable|email|max:200',
            'logo_url'          => 'nullable|string',
            'type'              => 'nullable|string|in:authority,operator',
            'reg_number'        => 'nullable|string|max:50',
            'region'            => 'nullable|string|max:100',
            'terminus_location' => 'nullable|string',
        ]);

        $agency->update($data);

        return response()->json($agency);
    }

    public function destroy(string $id): JsonResponse
    {
        $agency = Agency::withCount('routes')->findOrFail($id);

        if ($agency->routes_count > 0) {
            return response()->json(['message' => 'Cannot delete agency with associated routes.'], 422);
        }

        $agency->delete();

        return response()->json(['message' => 'Agency deleted.']);
    }

    public function onboardingStatus(Request $request, string $id): JsonResponse
    {
        $agency = Agency::withCount('routes')->findOrFail($id);
        $this->assertAgencyAllowed($request, $agency->agency_id);

        if ($agency->type === 'authority') {
            return response()->json(['message' => 'Transit authorities are not subject to onboarding.'], 422);
        }

        $steps = [
            'profile_set'       => !empty($agency->reg_number) || !empty($agency->logo_url),
            'routes_added'      => $agency->type === 'operator'
                                    ? $agency->operatedRoutes()->exists()
                                    : $agency->routes_count > 0,
            'split_configured'  => SplitConfig::where('agency_id', $id)->where('is_active', true)->exists(),
            'staff_invited'     => StaffInvitation::where('agency_id', $id)->whereNotNull('accepted_at')->exists(),
        ];

        $nextStep = array_key_first(array_filter($steps, fn($v) => !$v)) ?? 'complete';

        return response()->json([
            'onboarding_status'      => $agency->onboarding_status,
            'onboarding_completed_at' => $agency->onboarding_completed_at,
            'steps'                   => $steps,
            'next_step'               => $nextStep,
        ]);
    }

    // ── Operator Route Claims ─────────────────────────────────────────────────

    public function operatedRoutes(Request $request, string $id): JsonResponse
    {
        $agency = Agency::findOrFail($id);
        $this->assertAgencyAllowed($request, $agency->agency_id);

        if ($agency->type !== 'operator') {
            return response()->json(['message' => 'Only operator agencies have operated routes.'], 422);
        }

        $routes = $agency->operatedRoutes()
            ->with('agency:agency_id,agency_name')
            ->withCount('trips')
            ->orderBy('route_short_name')
            ->get();

        return response()->json($routes);
    }

    public function availableRoutes(Request $request, string $id): JsonResponse
    {
        $agency = Agency::findOrFail($id);
        $this->assertAgencyAllowed($request, $agency->agency_id);

        if ($agency->type !== 'operator') {
            return response()->json(['message' => 'Only operator agencies can claim routes.'], 422);
        }

        $claimedIds = $agency->operatedRoutes()->pluck('routes.route_id');

        $q = Route::whereNotIn('route_id', $claimedIds)
            ->with('agency:agency_id,agency_name')
            ->withCount('trips');

        if ($search = $request->input('search')) {
            $q->where(function ($sub) use ($search) {
                $sub->where('route_short_name', 'ilike', "%{$search}%")
                    ->orWhere('route_long_name', 'ilike', "%{$search}%");
            });
        }

        return response()->json($q->orderBy('route_short_name')->limit(150)->get());
    }

    public function claimRoutes(Request $request, string $id): JsonResponse
    {
        $agency = Agency::findOrFail($id);
        $this->assertAgencyAllowed($request, $agency->agency_id);

        if ($agency->type !== 'operator') {
            return response()->json(['message' => 'Only operator agencies can claim routes.'], 422);
        }

        $data = $request->validate([
            'route_ids'   => 'required|array|min:1',
            'route_ids.*' => 'required|string|exists:routes,route_id',
        ]);

        $agency->operatedRoutes()->syncWithoutDetaching($data['route_ids']);

        return response()->json(['claimed' => count($data['route_ids'])]);
    }

    public function unclaimRoute(Request $request, string $id, string $routeId): JsonResponse
    {
        $agency = Agency::findOrFail($id);
        $this->assertAgencyAllowed($request, $agency->agency_id);

        $agency->operatedRoutes()->detach($routeId);

        return response()->json(['message' => 'Route removed.']);
    }

    public function impersonate(Request $request, string $id): JsonResponse
    {
        $agency = Agency::findOrFail($id);

        $owner = User::whereHas('roles', fn($q) => $q->where('name', 'operator_owner'))
            ->whereHas('agencyScopes', fn($q) => $q->where('agency_id', $agency->agency_id))
            ->with('agencyScopes')
            ->first();

        abort_if(
            !$owner,
            404,
            "No operator_owner account found for {$agency->agency_name}. Invite one first via Access → Staff Invitations."
        );

        $token = $owner->createToken('console-impersonation', ['*'], now()->addHours(8))->plainTextToken;

        $userData                  = $owner->load('agencyScopes')->toArray();
        $userData['permissions']   = $owner->getEffectivePermissions();
        $userData['agency_scopes'] = $owner->agencyScopes->pluck('agency_id')->toArray();
        $userData['console_role']  = $owner->roles->first()?->name ?? $owner->role;

        return response()->json(['token' => $token, 'user' => $userData]);
    }

    public function completeOnboarding(Request $request, string $id): JsonResponse
    {
        $agency = Agency::withCount('routes')->findOrFail($id);
        $this->assertAgencyAllowed($request, $agency->agency_id);

        if ($agency->type === 'authority') {
            return response()->json(['message' => 'Transit authorities are not subject to onboarding.'], 422);
        }

        if ($agency->onboarding_status === 'active') {
            return response()->json(['message' => 'Onboarding already complete.'], 409);
        }

        $agency->update([
            'onboarding_status'       => 'active',
            'onboarding_completed_at' => now(),
        ]);

        return response()->json(['message' => 'Onboarding complete. SACCO is now active.']);
    }
}
