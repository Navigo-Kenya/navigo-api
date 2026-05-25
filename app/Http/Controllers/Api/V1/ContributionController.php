<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\ContributionVote;
use App\Services\ContributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContributionController extends Controller
{
    public function __construct(private ContributionService $service) {}

    public function index(Request $request): JsonResponse
    {
        $contributions = Contribution::where('user_id', $request->user()->id)
            ->with('stop:id,name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $contributions]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'        => 'required|in:delay_report,stop_review,stop_photo,stop_edit,route_correction,new_stop',
            'stop_id'     => 'nullable|exists:stops,id',
            'title'       => 'nullable|string|max:120',
            'description' => 'nullable|string|max:1000',
            'data'        => 'nullable|array',
        ]);

        // One review per stop per user — upsert
        if ($validated['type'] === 'stop_review' && !empty($validated['stop_id'])) {
            $existing = Contribution::where('user_id', $request->user()->id)
                ->where('type', 'stop_review')
                ->where('stop_id', $validated['stop_id'])
                ->first();
            if ($existing) {
                $existing->update(['data' => $validated['data'] ?? $existing->data]);
                return response()->json([
                    'data'           => $existing->load('stop'),
                    'points_awarded' => 0,
                    'new_badges'     => [],
                    'new_level'      => null,
                ], 200);
            }
        }

        $result = $this->service->create($request->user(), $validated);

        return response()->json([
            'data'           => $result['contribution'],
            'points_awarded' => $result['points_awarded'],
            'new_badges'     => $result['new_badges'],
            'new_level'      => $result['new_level'],
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $contribution = Contribution::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($contribution->status !== 'pending') {
            return response()->json(['message' => 'Only pending contributions can be deleted.'], 422);
        }

        $contribution->delete();

        return response()->json(null, 204);
    }

    public function nearby(Request $request): JsonResponse
    {
        $request->validate([
            'lat'    => 'required|numeric',
            'lng'    => 'required|numeric',
            'radius' => 'nullable|integer|min:100|max:10000',
        ]);

        $contributions = Contribution::where('type', 'delay_report')
            ->active()
            ->nearby($request->float('lat'), $request->float('lng'), $request->integer('radius', 5000))
            ->with('stop:id,name')
            ->withCount(['votes as up_votes'   => fn ($q) => $q->where('vote', 'up'),
                         'votes as down_votes' => fn ($q) => $q->where('vote', 'down')])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json(['data' => $contributions]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $contribution = Contribution::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $validated = $request->validate(['data' => 'required|array']);

        $contribution->update(['data' => $validated['data']]);

        return response()->json(['data' => $contribution->load('stop')]);
    }

    public function vote(Request $request, int $id): JsonResponse
    {
        $request->validate(['vote' => 'required|in:up,down']);

        $contribution = Contribution::findOrFail($id);

        ContributionVote::updateOrCreate(
            ['user_id' => $request->user()->id, 'contribution_id' => $contribution->id],
            ['vote'    => $request->input('vote')],
        );

        return response()->json(['message' => 'Vote recorded.']);
    }
}
