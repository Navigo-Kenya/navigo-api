<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\RouteSla;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = RouteSla::with('route:route_id,route_short_name,route_long_name');
        $this->scopeQuery($q, $request);

        if ($request->has('active')) {
            $q->where('active', (bool) $request->input('active'));
        }

        return response()->json($q->orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'               => 'required|string|exists:agencies,agency_id',
            'route_id'                => 'required|string|exists:routes,route_id',
            'target_headway_minutes'  => 'required|integer|min:1|max:120',
            'alert_threshold_minutes' => 'integer|min:1|max:60',
            'active'                  => 'boolean',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);

        $sla = RouteSla::updateOrCreate(
            ['agency_id' => $data['agency_id'], 'route_id' => $data['route_id']],
            $data
        );

        return response()->json($sla->load('route:route_id,route_short_name,route_long_name'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $sla = RouteSla::findOrFail($id);
        $this->assertAgencyAllowed($request, $sla->agency_id);

        $data = $request->validate([
            'target_headway_minutes'  => 'sometimes|integer|min:1|max:120',
            'alert_threshold_minutes' => 'sometimes|integer|min:1|max:60',
            'active'                  => 'boolean',
        ]);

        $sla->update($data);
        return response()->json($sla->load('route:route_id,route_short_name,route_long_name'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $sla = RouteSla::findOrFail($id);
        $this->assertAgencyAllowed($request, $sla->agency_id);
        $sla->delete();
        return response()->json(null, 204);
    }
}
