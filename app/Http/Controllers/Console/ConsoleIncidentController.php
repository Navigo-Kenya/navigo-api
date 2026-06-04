<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsoleIncidentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Incident::with(['vehicle:id,plate']);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('route_id')) {
            $query->where('route_id', $request->route_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $incidents = $query->orderByDesc('created_at')->paginate(30);

        return response()->json($incidents);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'           => 'required|in:accident,near_miss,crime,infrastructure,other',
            'severity'       => 'required|in:low,medium,high,critical',
            'route_id'       => 'nullable|string|exists:routes,route_id',
            'stop_id'        => 'nullable|string|exists:stops,id',
            'vehicle_id'     => 'nullable|integer|exists:vehicles,id',
            'description'    => 'required|string',
            'response_taken' => 'nullable|string',
            'reported_by'    => 'nullable|string|max:255',
        ]);

        $data['status']       = 'open';
        $data['created_by']   = $request->user()?->id ?? 'system';
        $slaMins = Incident::SLA_MINUTES[$data['severity']] ?? 1440;
        $data['sla_deadline'] = now()->addMinutes($slaMins);

        $incident = Incident::create($data);

        return response()->json($incident->load('vehicle:id,plate'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);

        $data = $request->validate([
            'type'           => 'sometimes|in:accident,near_miss,crime,infrastructure,other',
            'severity'       => 'sometimes|in:low,medium,high,critical',
            'status'         => 'sometimes|in:open,investigating,resolved',
            'route_id'       => 'nullable|string',
            'stop_id'        => 'nullable|string',
            'vehicle_id'     => 'nullable|integer|exists:vehicles,id',
            'description'    => 'sometimes|string',
            'response_taken' => 'nullable|string',
        ]);

        $incident->update($data);

        return response()->json($incident->load('vehicle:id,plate'));
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);

        $data = $request->validate([
            'response_taken' => 'nullable|string',
        ]);

        $resolvedAt = now();
        $resolutionMins = $incident->created_at
            ? (int) $incident->created_at->diffInMinutes($resolvedAt)
            : null;

        $incident->update([
            'status'                => 'resolved',
            'resolved_at'           => $resolvedAt,
            'resolution_time_mins'  => $resolutionMins,
            'response_taken'        => $data['response_taken'] ?? $incident->response_taken,
        ]);

        return response()->json($incident->load('vehicle:id,plate'));
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);

        $data = $request->validate([
            'assigned_to' => 'nullable|integer|exists:users,id',
        ]);

        $incident->update(['assigned_to' => $data['assigned_to']]);

        return response()->json($incident->load(['vehicle:id,plate', 'assignedTo:id,name']));
    }

    public function stats(): JsonResponse
    {
        $openCount     = Incident::where('status', 'open')->count();
        $criticalCount = Incident::where('severity', 'critical')
            ->whereIn('status', ['open', 'investigating'])
            ->count();

        $resolvedThisMonth = Incident::where('status', 'resolved')
            ->whereMonth('resolved_at', now()->month)
            ->whereYear('resolved_at', now()->year)
            ->count();

        $avgResolution = Incident::where('status', 'resolved')
            ->whereNotNull('resolution_time_mins')
            ->avg('resolution_time_mins');

        $byType = Incident::select('type', DB::raw('COUNT(*) as count'))
            ->groupBy('type')
            ->pluck('count', 'type');

        $bySeverity = Incident::select('severity', DB::raw('COUNT(*) as count'))
            ->groupBy('severity')
            ->pluck('count', 'severity');

        return response()->json([
            'open_count'            => $openCount,
            'critical_count'        => $criticalCount,
            'resolved_this_month'   => $resolvedThisMonth,
            'avg_resolution_mins'   => $avgResolution ? round($avgResolution) : null,
            'by_type'               => $byType,
            'by_severity'           => $bySeverity,
        ]);
    }
}
