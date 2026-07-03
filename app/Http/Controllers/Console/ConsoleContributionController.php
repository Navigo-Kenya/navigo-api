<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Services\ContributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsoleContributionController extends Controller
{
    public function __construct(private ContributionService $service) {}

    public function index(Request $request): JsonResponse
    {
        $q = Contribution::with('user:id,name,avatar,points')
            ->withCount('votes');

        if ($status = $request->input('status')) {
            // Console UI historically filters by "declined"; DB canon is "rejected".
            $q->where('status', $status === 'declined' ? 'rejected' : $status);
        }

        if ($type = $request->input('type')) {
            $q->where('type', $type);
        }

        if ($from = $request->input('from')) {
            $q->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $q->whereDate('created_at', '<=', $to);
        }

        if ($search = $request->input('search')) {
            $q->where('description', 'ilike', "%{$search}%");
        }

        $contributions = $q->latest()->paginate((int) $request->input('per_page', 20));

        return response()->json($contributions);
    }

    public function show(int $id): JsonResponse
    {
        $contribution = Contribution::with([
            'user:id,name,email,avatar,points',
            'votes',
        ])->findOrFail($id);

        return response()->json($contribution);
    }

    public function approve(int $id): JsonResponse
    {
        $contribution = Contribution::where('status', 'pending')->findOrFail($id);

        // ContributionService owns the approval side-effects: correct
        // POINTS_MAP award (not a flat +10), first-photo bonus, badge checks,
        // landmark write-through, and the "points earned" push.
        $this->service->approve($contribution, auth()->id());

        return response()->json(['message' => 'Contribution approved.']);
    }

    public function decline(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $contribution = Contribution::where('status', 'pending')->findOrFail($id);

        $contribution->update([
            'status'         => 'rejected', // canonical status; console shows "Declined"
            'decline_reason' => $data['reason'],
            'reviewed_at'    => now(),
            'reviewed_by'    => auth()->id(),
        ]);

        return response()->json(['message' => 'Contribution declined.']);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $contribution = Contribution::findOrFail($id);

        $data = $request->validate([
            'description'  => 'sometimes|string',
            'lat'          => 'sometimes|numeric',
            'lng'          => 'sometimes|numeric',
            'stop_name'    => 'sometimes|string|max:255',
        ]);

        $contribution->update($data);

        return response()->json($contribution);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $contribution = Contribution::findOrFail($id);

        $data = $request->validate([
            'assigned_to' => 'nullable|integer|exists:users,id',
        ]);

        $contribution->update(['assigned_to' => $data['assigned_to']]);

        return response()->json($contribution);
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);

        // Per-item service approval so points/badges/write-throughs all apply.
        $contributions = Contribution::whereIn('id', $data['ids'])->where('status', 'pending')->get();
        foreach ($contributions as $contribution) {
            $this->service->approve($contribution, auth()->id());
        }

        return response()->json(['approved' => $contributions->count()]);
    }

    public function bulkDecline(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'    => 'required|array',
            'ids.*'  => 'integer',
            'reason' => 'required|string|max:500',
        ]);

        $count = Contribution::whereIn('id', $data['ids'])->where('status', 'pending')
            ->update([
                'status'         => 'rejected', // canonical status; console shows "Declined"
                'decline_reason' => $data['reason'],
                'reviewed_at'    => now(),
                'reviewed_by'    => auth()->id(),
            ]);

        return response()->json(['declined' => $count]);
    }
}
