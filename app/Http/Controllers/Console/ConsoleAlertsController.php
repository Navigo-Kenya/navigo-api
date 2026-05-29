<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ServiceAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsoleAlertsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ServiceAlert::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }

        if ($request->filled('affected_type')) {
            $query->where('affected_type', $request->affected_type);
        }

        $alerts = $query->orderByDesc('created_at')->paginate(30);

        return response()->json($alerts);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'         => 'required|string|max:255',
            'description'   => 'nullable|string',
            'severity'      => 'required|in:info,warning,critical',
            'effect'        => 'required|in:detour,reduced_service,cancellation,other',
            'affected_type' => 'required|in:route,stop,all',
            'affected_id'   => 'nullable|string|max:255',
            'starts_at'     => 'required|date',
            'ends_at'       => 'nullable|date|after:starts_at',
        ]);

        $data['status']     = 'draft';
        $data['created_by'] = $request->user()?->id ?? 'system';

        $alert = ServiceAlert::create($data);

        return response()->json($alert, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $alert = ServiceAlert::findOrFail($id);

        $data = $request->validate([
            'title'         => 'sometimes|string|max:255',
            'description'   => 'nullable|string',
            'severity'      => 'sometimes|in:info,warning,critical',
            'effect'        => 'sometimes|in:detour,reduced_service,cancellation,other',
            'affected_type' => 'sometimes|in:route,stop,all',
            'affected_id'   => 'nullable|string|max:255',
            'starts_at'     => 'sometimes|date',
            'ends_at'       => 'nullable|date',
        ]);

        $alert->update($data);

        return response()->json($alert);
    }

    public function activate(int $id): JsonResponse
    {
        $alert = ServiceAlert::findOrFail($id);
        $alert->update(['status' => 'active']);

        return response()->json($alert);
    }

    public function expire(int $id): JsonResponse
    {
        $alert = ServiceAlert::findOrFail($id);
        $alert->update(['status' => 'expired']);

        return response()->json($alert);
    }

    public function destroy(int $id): JsonResponse
    {
        ServiceAlert::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    // ── Public GTFS-RT feed ───────────────────────────────────────────────────

    public function gtfsRtFeed(): JsonResponse
    {
        $alerts = ServiceAlert::where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->get();

        $entities = $alerts->map(function (ServiceAlert $alert) {
            $informedEntity = match ($alert->affected_type) {
                'route' => [['route_id' => $alert->affected_id]],
                'stop'  => [['stop_id'  => $alert->affected_id]],
                default => [[]],
            };

            $effectMap = [
                'detour'          => 'DETOUR',
                'reduced_service' => 'REDUCED_SERVICE',
                'cancellation'    => 'NO_SERVICE',
                'other'           => 'UNKNOWN_EFFECT',
            ];

            $entry = [
                'id'    => 'alert-'.$alert->id,
                'alert' => [
                    'informed_entity' => $informedEntity,
                    'cause'           => 'OTHER_CAUSE',
                    'effect'          => $effectMap[$alert->effect] ?? 'UNKNOWN_EFFECT',
                    'header_text'     => [
                        'translation' => [['text' => $alert->title, 'language' => 'en']],
                    ],
                    'active_period' => [
                        [
                            'start' => $alert->starts_at->timestamp,
                            'end'   => $alert->ends_at?->timestamp,
                        ],
                    ],
                ],
            ];

            if ($alert->description) {
                $entry['alert']['description_text'] = [
                    'translation' => [['text' => $alert->description, 'language' => 'en']],
                ];
            }

            return $entry;
        });

        return response()->json([
            'header' => [
                'gtfs_realtime_version' => '2.0',
                'timestamp'             => now()->timestamp,
            ],
            'entity' => $entities,
        ]);
    }
}
