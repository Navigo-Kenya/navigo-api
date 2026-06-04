<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\StageQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StageQueueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'route_id'  => 'required|string|exists:routes,route_id',
            'agency_id' => 'nullable|string',
        ]);

        $q = StageQueue::with(['vehicle:id,plate,model', 'route:route_id,route_short_name'])
            ->where('route_id', $data['route_id'])
            ->where('status', 'waiting')
            ->orderBy('queue_position');

        if (!empty($data['agency_id'])) {
            $q->where('agency_id', $data['agency_id']);
        } else {
            $scope = $this->agencyScope($request);
            if ($scope !== null) {
                $q->whereIn('agency_id', $scope);
            }
        }

        return response()->json($q->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'  => 'required|string|exists:agencies,agency_id',
            'route_id'   => 'required|string|exists:routes,route_id',
            'vehicle_id' => 'required|integer|exists:vehicles,id',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);

        $maxPos = StageQueue::where('route_id', $data['route_id'])
            ->where('agency_id', $data['agency_id'])
            ->where('status', 'waiting')
            ->max('queue_position') ?? 0;

        $entry = StageQueue::create([
            ...$data,
            'queue_position' => $maxPos + 1,
            'created_by'     => $request->user()?->id,
        ]);

        return response()->json($entry->load('vehicle:id,plate,model'), 201);
    }

    public function depart(int $id): JsonResponse
    {
        $entry = StageQueue::findOrFail($id);
        $entry->update([
            'status'      => 'departed',
            'departed_at' => now(),
        ]);

        // Compact queue positions for remaining waiting vehicles
        DB::table('stage_queues')
            ->where('route_id', $entry->route_id)
            ->where('agency_id', $entry->agency_id)
            ->where('status', 'waiting')
            ->where('queue_position', '>', $entry->queue_position)
            ->decrement('queue_position');

        return response()->json($entry);
    }

    public function skip(int $id): JsonResponse
    {
        $entry = StageQueue::findOrFail($id);
        $entry->update(['status' => 'skipped']);

        DB::table('stage_queues')
            ->where('route_id', $entry->route_id)
            ->where('agency_id', $entry->agency_id)
            ->where('status', 'waiting')
            ->where('queue_position', '>', $entry->queue_position)
            ->decrement('queue_position');

        return response()->json($entry);
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order'   => 'required|array',
            'order.*' => 'integer|exists:stage_queues,id',
        ]);

        foreach ($data['order'] as $pos => $queueId) {
            DB::table('stage_queues')
                ->where('id', $queueId)
                ->update(['queue_position' => $pos + 1]);
        }

        return response()->json(['message' => 'Queue reordered.']);
    }
}
