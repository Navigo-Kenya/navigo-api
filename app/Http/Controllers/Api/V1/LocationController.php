<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StopResource;
use App\Models\Contribution;
use App\Services\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LocationController extends Controller
{
    public function __construct(private LocationService $locationService) {}

    public function nearby(Request $request)
    {
        $validated = $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'radius' => 'nullable|integer|max:5000',
        ]);

        $stops = $this->locationService->getNearbyStops(
            $validated['lat'],
            $validated['lng'],
            $validated['radius'] ?? 1500
        );

        return StopResource::collection($stops);
    }

    public function all()
    {
        return response()->json(['data' => $this->locationService->getAllStops()]);
    }

    public function show(string $id)
    {
        $stop = $this->locationService->getStop($id);
        if (!$stop) return response()->json(['error' => 'Stop not found'], 404);
        return response()->json(['data' => $stop]);
    }

    public function search(Request $request)
    {
        $validated = $request->validate(['q' => 'required|string|min:2']);

        $stops = $this->locationService->searchStops($validated['q']);

        Log::info("Searched for stops with query: '{$validated['q']}' - Found " . \count($stops) . " results.");

        return StopResource::collection($stops);
    }

    public function stopReviews(string $id): JsonResponse
    {
        $reviews = Contribution::where('stop_id', $id)
            ->where('type', 'stop_review')
            ->whereIn('status', ['auto_approved', 'approved'])
            ->with('user:id,name,avatar')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($c) => [
                'id'         => $c->id,
                'user_id'    => $c->user_id,
                'user'       => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name, 'avatar' => $c->user->avatar] : null,
                'data'       => $c->data,
                'created_at' => $c->created_at,
            ]);

        return response()->json(['data' => $reviews]);
    }

    public function stopPhotos(string $id): JsonResponse
    {
        $photos = Contribution::where('stop_id', $id)
            ->where('type', 'stop_photo')
            ->where('status', 'approved')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($c) => [
                'id'         => $c->id,
                'data'       => $c->data,
                'created_at' => $c->created_at,
            ]);

        return response()->json(['data' => $photos]);
    }
}
