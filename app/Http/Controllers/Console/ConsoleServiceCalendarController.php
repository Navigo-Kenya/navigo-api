<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ServiceCalendar;
use App\Models\ServiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsoleServiceCalendarController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            ServiceCalendar::withCount('trips')->orderBy('name')->get()
        );
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(
            ServiceCalendar::with('exceptions')->withCount('trips')->findOrFail($id)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_id' => 'required|string|max:50|unique:service_calendars,service_id',
            'name'       => 'required|string|max:100',
            'monday'     => 'required|boolean',
            'tuesday'    => 'required|boolean',
            'wednesday'  => 'required|boolean',
            'thursday'   => 'required|boolean',
            'friday'     => 'required|boolean',
            'saturday'   => 'required|boolean',
            'sunday'     => 'required|boolean',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        return response()->json(ServiceCalendar::create($data), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $calendar = ServiceCalendar::findOrFail($id);

        $data = $request->validate([
            'name'       => 'sometimes|string|max:100',
            'monday'     => 'sometimes|boolean',
            'tuesday'    => 'sometimes|boolean',
            'wednesday'  => 'sometimes|boolean',
            'thursday'   => 'sometimes|boolean',
            'friday'     => 'sometimes|boolean',
            'saturday'   => 'sometimes|boolean',
            'sunday'     => 'sometimes|boolean',
            'start_date' => 'sometimes|date',
            'end_date'   => 'sometimes|date|after_or_equal:start_date',
        ]);

        $calendar->update($data);

        return response()->json($calendar);
    }

    public function destroy(string $id): JsonResponse
    {
        $calendar = ServiceCalendar::withCount('trips')->findOrFail($id);

        if ($calendar->trips_count > 0) {
            return response()->json(['message' => 'Cannot delete a calendar that has associated trips.'], 422);
        }

        $calendar->delete();

        return response()->json(['message' => 'Calendar deleted.']);
    }

    public function addException(Request $request, string $id): JsonResponse
    {
        ServiceCalendar::findOrFail($id);

        $data = $request->validate([
            'date'           => 'required|date',
            'exception_type' => 'required|integer|in:1,2',
            'note'           => 'nullable|string|max:200',
        ]);

        $exception = ServiceException::create([
            'service_id'     => $id,
            'date'           => $data['date'],
            'exception_type' => $data['exception_type'],
            'note'           => $data['note'] ?? null,
        ]);

        return response()->json($exception, 201);
    }

    public function removeException(string $id, int $eid): JsonResponse
    {
        $exception = ServiceException::where('service_id', $id)->findOrFail($eid);
        $exception->delete();

        return response()->json(['message' => 'Exception removed.']);
    }
}
