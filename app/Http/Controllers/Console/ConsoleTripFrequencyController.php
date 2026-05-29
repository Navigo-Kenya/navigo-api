<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\TripFrequency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsoleTripFrequencyController extends Controller
{
    public function index(string $tripId): JsonResponse
    {
        Trip::findOrFail($tripId);

        return response()->json(
            TripFrequency::where('trip_id', $tripId)->orderBy('start_time')->get()
        );
    }

    public function store(Request $request, string $tripId): JsonResponse
    {
        Trip::findOrFail($tripId);

        $data = $request->validate([
            'start_time'   => ['required', 'string', 'regex:/^\d{1,2}:\d{2}:\d{2}$/'],
            'end_time'     => ['required', 'string', 'regex:/^\d{1,2}:\d{2}:\d{2}$/', 'gt:start_time'],
            'headway_secs' => 'required|integer|min:1',
            'exact_times'  => 'integer|in:0,1',
        ]);

        $freq = TripFrequency::create(array_merge($data, ['trip_id' => $tripId]));

        return response()->json($freq, 201);
    }

    public function update(Request $request, string $tripId, int $id): JsonResponse
    {
        $freq = TripFrequency::where('trip_id', $tripId)->findOrFail($id);

        $data = $request->validate([
            'start_time'   => ['sometimes', 'string', 'regex:/^\d{1,2}:\d{2}:\d{2}$/'],
            'end_time'     => ['sometimes', 'string', 'regex:/^\d{1,2}:\d{2}:\d{2}$/'],
            'headway_secs' => 'sometimes|integer|min:1',
            'exact_times'  => 'sometimes|integer|in:0,1',
        ]);

        $freq->update($data);

        return response()->json($freq);
    }

    public function destroy(string $tripId, int $id): JsonResponse
    {
        $freq = TripFrequency::where('trip_id', $tripId)->findOrFail($id);
        $freq->delete();

        return response()->json(['message' => 'Frequency deleted.']);
    }
}
