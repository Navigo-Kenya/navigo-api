<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedPlacesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $places = $request->user()->savedPlaces()->latest()->get();

        return response()->json($places);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'lat'      => 'required|numeric',
            'lng'      => 'required|numeric',
            'type'     => 'required|string|in:stop,location',
            'place_id' => 'nullable|string',
            'list'     => 'required_without:pin|nullable|string|max:80',
            'pin'      => 'nullable|string|in:home,work',
            'category' => 'nullable|string|max:100',
            'note'     => 'nullable|string|max:500',
        ]);

        $user = $request->user();

        // Pin is mutually exclusive, remove existing same-pin entry
        if (!empty($data['pin'])) {
            $user->savedPlaces()->where('pin', $data['pin'])->delete();
            $data['list'] = null;
        }

        $place = $user->savedPlaces()->create($data);

        return response()->json($place, 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $place = $request->user()->savedPlaces()->findOrFail($id);
        $place->delete();

        return response()->json(null, 204);
    }
}
