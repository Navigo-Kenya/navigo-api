<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\NetworkScenario;
use App\Models\Route;
use App\Models\ScenarioOverride;
use App\Models\Stop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsoleScenarioController extends Controller
{
    public function index(): JsonResponse
    {
        $scenarios = NetworkScenario::withCount('overrides')
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($scenarios);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:200',
            'description' => 'nullable|string',
        ]);

        $scenario = NetworkScenario::create(array_merge($data, [
            'created_by' => auth()->id(),
            'status'     => 'draft',
        ]));

        return response()->json($scenario, 201);
    }

    public function show(int $id): JsonResponse
    {
        $scenario = NetworkScenario::with(['overrides', 'createdBy:id,name'])->findOrFail($id);
        return response()->json($scenario);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $scenario = NetworkScenario::findOrFail($id);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:200',
            'description' => 'nullable|string',
            'status'      => 'sometimes|in:draft,archived',
        ]);

        $scenario->update($data);

        return response()->json($scenario);
    }

    public function destroy(int $id): JsonResponse
    {
        NetworkScenario::findOrFail($id)->delete();
        return response()->json(['message' => 'Scenario deleted.']);
    }

    public function addOverride(Request $request, int $id): JsonResponse
    {
        $scenario = NetworkScenario::findOrFail($id);

        $data = $request->validate([
            'entity_type' => 'required|in:route,stop,trip,shape',
            'entity_id'   => 'nullable|string',
            'action'      => 'required|in:add,modify,delete',
            'data'        => 'required|array',
        ]);

        $override = ScenarioOverride::updateOrCreate(
            [
                'scenario_id' => $scenario->id,
                'entity_type' => $data['entity_type'],
                'entity_id'   => $data['entity_id'] ?? null,
            ],
            [
                'action' => $data['action'],
                'data'   => $data['data'],
            ]
        );

        return response()->json($override, 201);
    }

    public function removeOverride(int $id, int $oid): JsonResponse
    {
        ScenarioOverride::where('scenario_id', $id)->findOrFail($oid)->delete();
        return response()->json(['message' => 'Override removed.']);
    }

    // Merge production data with scenario overrides for preview
    public function compare(int $id): JsonResponse
    {
        $scenario  = NetworkScenario::with('overrides')->findOrFail($id);
        $overrides = $scenario->overrides;

        // Production graph
        $prodRoutes = Route::with('trips.shape')->get()->keyBy('route_id');
        $prodStops  = Stop::all()->keyBy('id');

        // Apply overrides
        $scenarioRoutes = $prodRoutes->toArray();
        $scenarioStops  = $prodStops->toArray();

        foreach ($overrides as $override) {
            $type = $override->entity_type;
            $key  = $override->entity_id;

            if ($override->action === 'delete') {
                if ($type === 'route') {
                    unset($scenarioRoutes[$key]);
                } elseif ($type === 'stop') {
                    unset($scenarioStops[$key]);
                }
            } elseif ($override->action === 'modify' && $key) {
                if ($type === 'route' && isset($scenarioRoutes[$key])) {
                    $scenarioRoutes[$key] = array_merge($scenarioRoutes[$key], $override->data);
                } elseif ($type === 'stop' && isset($scenarioStops[$key])) {
                    $scenarioStops[$key] = array_merge($scenarioStops[$key], $override->data);
                }
            } elseif ($override->action === 'add') {
                if ($type === 'route') {
                    $newId = $override->data['route_id'] ?? 'new_' . $override->id;
                    $scenarioRoutes[$newId] = $override->data;
                } elseif ($type === 'stop') {
                    $newId = $override->data['id'] ?? 'new_' . $override->id;
                    $scenarioStops[$newId] = $override->data;
                }
            }
        }

        return response()->json([
            'production' => [
                'routes' => array_values($prodRoutes->toArray()),
                'stops'  => array_values($prodStops->toArray()),
            ],
            'scenario' => [
                'routes' => array_values($scenarioRoutes),
                'stops'  => array_values($scenarioStops),
            ],
        ]);
    }

    // Publish: apply all add/modify/delete overrides to production (superadmin only)
    public function publish(int $id): JsonResponse
    {
        $scenario = NetworkScenario::with('overrides')->findOrFail($id);

        if ($scenario->status === 'published') {
            return response()->json(['message' => 'Scenario already published.'], 422);
        }

        DB::transaction(function () use ($scenario) {
            foreach ($scenario->overrides as $override) {
                $this->applyOverride($override);
            }
            $scenario->update([
                'status'       => 'published',
                'published_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Scenario published and applied to production.']);
    }

    private function applyOverride(ScenarioOverride $override): void
    {
        $type = $override->entity_type;
        $key  = $override->entity_id;

        if ($type === 'route') {
            if ($override->action === 'delete' && $key) {
                Route::where('route_id', $key)->delete();
            } elseif ($override->action === 'modify' && $key) {
                Route::where('route_id', $key)->update($override->data);
            } elseif ($override->action === 'add') {
                Route::create($override->data);
            }
        } elseif ($type === 'stop') {
            if ($override->action === 'delete' && $key) {
                Stop::where('id', $key)->delete();
            } elseif ($override->action === 'modify' && $key) {
                Stop::where('id', $key)->update($override->data);
            } elseif ($override->action === 'add') {
                Stop::create($override->data);
            }
        }
    }
}
