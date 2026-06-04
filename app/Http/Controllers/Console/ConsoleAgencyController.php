<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\SplitConfig;
use App\Models\StaffInvitation;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsoleAgencyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Agency::withCount('routes')->orderBy('agency_name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'       => 'required|string|unique:agencies,agency_id',
            'agency_name'     => 'required|string|max:200',
            'agency_url'      => 'required|string|url|max:255',
            'agency_timezone' => 'required|string|max:50',
            'agency_lang'     => 'nullable|string|max:10',
            'agency_phone'    => 'nullable|string|max:50',
            'agency_email'    => 'nullable|email|max:200',
            'reg_number'      => 'nullable|string|max:50',
            'region'          => 'nullable|string|max:100',
            'terminus_location' => 'nullable|string',
        ]);

        $agency = Agency::create($data);

        Wallet::firstOrCreate(
            ['entity_type' => 'agency', 'entity_id' => $agency->agency_id],
            ['balance' => 0, 'currency' => 'KES'],
        );

        return response()->json($agency, 201);
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

        $steps = [
            'profile_set'       => !empty($agency->reg_number) || !empty($agency->logo_url),
            'routes_added'      => $agency->routes_count > 0,
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

    public function completeOnboarding(Request $request, string $id): JsonResponse
    {
        $agency = Agency::withCount('routes')->findOrFail($id);
        $this->assertAgencyAllowed($request, $agency->agency_id);

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
