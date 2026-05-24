<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedJourneysController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $journeys = $request->user()->savedJourneys()->latest()->get();

        return response()->json($journeys);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label'     => 'nullable|string|max:255',
            'from_name' => 'required|string|max:255',
            'from_lat'  => 'required|numeric',
            'from_lng'  => 'required|numeric',
            'from_id'   => 'nullable|string',
            'from_type' => 'required|string|in:stop,location',
            'to_name'   => 'required|string|max:255',
            'to_lat'    => 'required|numeric',
            'to_lng'    => 'required|numeric',
            'to_id'     => 'nullable|string',
            'to_type'   => 'required|string|in:stop,location',
            'summary'   => 'required|string',
            'duration'  => 'required|integer|min:0',
            'route'     => 'required|array',
        ]);

        $journey = $request->user()->savedJourneys()->create($data);

        return response()->json($journey, 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $journey = $request->user()->savedJourneys()->findOrFail($id);
        $journey->delete();

        return response()->json(null, 204);
    }
}
