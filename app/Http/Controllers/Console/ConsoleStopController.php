<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Stop;
use App\Models\StopTime;
use App\Models\AgencyStopClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsoleStopController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Stop::select(
                'id', 'name', 'location_t', 'popularity_score', 'route_nams', 'updated_at', 'parent_sta'
            )
            ->selectRaw('ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng')
            ->withCount('contributions');

        if ($search = $request->input('search')) {
            $q->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('id', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('agency_id')) {
            $agencyId = $request->agency_id;
            $q->whereExists(fn ($sub) =>
                $sub->from('stop_times')
                    ->join('trips', 'trips.trip_id', '=', 'stop_times.trip_id')
                    ->join('routes', 'routes.route_id', '=', 'trips.route_id')
                    ->whereColumn('stop_times.stop_id', 'stops.id')
                    ->where('routes.agency_id', $agencyId)
            );
        }

        // Bounding box filter: ?bbox=lat_min,lng_min,lat_max,lng_max
        if ($bbox = $request->input('bbox')) {
            [$latMin, $lngMin, $latMax, $lngMax] = array_map('floatval', explode(',', $bbox));
            $q->whereRaw(
                'ST_Within(location, ST_MakeEnvelope(?, ?, ?, ?, 4326))',
                [$lngMin, $latMin, $lngMax, $latMax]
            );
        }

        if ($request->filled('type')) {
            $q->where('location_t', (int) $request->input('type'));
        }

        $sort  = in_array($request->input('sort'), ['updated_at', 'popularity_score', 'name', 'trip_count'])
               ? $request->input('sort') : 'updated_at';
        $order = $request->input('order', 'desc') === 'asc' ? 'asc' : 'desc';

        $stops = $q->orderBy($sort, $order)->paginate((int) $request->input('per_page', 30));

        return response()->json($stops);
    }

    public function show(string $id): JsonResponse
    {
        $stop = Stop::selectRaw("*, ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng")
            ->with([
                'contributions' => fn ($q) => $q->with('user')->latest()->limit(20),
                'stopTimes'     => fn ($q) => $q->orderBy('stop_sequence')->limit(100),
            ])
            ->findOrFail($id);

        return response()->json($stop);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id'         => 'required|string|unique:stops,id',
            'name'       => 'required|string|max:255',
            'lat'        => 'required|numeric|between:-90,90',
            'lng'        => 'required|numeric|between:-180,180',
            'parent_sta' => 'nullable|string|exists:stops,id',
        ]);

        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];

        Stop::create([
            'id'         => $data['id'],
            'name'       => $data['name'],
            'location'   => DB::raw("ST_SetSRID(ST_MakePoint({$lng}, {$lat}), 4326)"),
            'parent_sta' => $data['parent_sta'] ?? null,
        ]);

        $stop = Stop::selectRaw("*, ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng")
            ->findOrFail($data['id']);

        return response()->json($stop, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $stop = Stop::findOrFail($id);

        $data = $request->validate([
            'name'       => 'sometimes|string|max:255',
            'lat'        => 'sometimes|numeric|between:-90,90',
            'lng'        => 'sometimes|numeric|between:-180,180',
            'parent_sta' => 'nullable|string|exists:stops,id',
            'route_ids'  => 'nullable|string',
            'route_nams' => 'nullable|string',
        ]);

        $updates = [];

        if (isset($data['name'])) {
            $updates['name'] = $data['name'];
        }
        if (isset($data['lat']) && isset($data['lng'])) {
            $lat = (float) $data['lat'];
            $lng = (float) $data['lng'];
            $updates['location'] = DB::raw("ST_SetSRID(ST_MakePoint({$lng}, {$lat}), 4326)");
        }
        if (array_key_exists('parent_sta', $data)) {
            $updates['parent_sta'] = $data['parent_sta'];
        }
        if (array_key_exists('route_ids', $data)) {
            $updates['route_ids'] = $data['route_ids'];
        }
        if (array_key_exists('route_nams', $data)) {
            $updates['route_nams'] = $data['route_nams'];
        }

        if (!empty($updates)) {
            $stop->update($updates);
        }

        return response()->json(
            Stop::selectRaw("*, ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng")
                ->findOrFail($stop->id)
        );
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($request->user()->isOperator()) {
            return response()->json(['message' => 'Operators cannot delete stops.'], 403);
        }

        $stop = Stop::findOrFail($id);
        $stop->delete();

        return response()->json(['message' => 'Stop deleted.']);
    }

    public function storeStopTime(Request $request, string $id): JsonResponse
    {
        Stop::findOrFail($id);

        $data = $request->validate([
            'trip_id'        => 'required|string|max:255',
            'arrival_time'   => ['required', 'string', 'regex:/^\d{1,2}:\d{2}:\d{2}$/'],
            'departure_time' => ['required', 'string', 'regex:/^\d{1,2}:\d{2}:\d{2}$/'],
            'stop_sequence'  => 'required|integer|min:0',
        ]);

        $stopTime = StopTime::create([
            'stop_id'        => $id,
            'trip_id'        => $data['trip_id'],
            'arrival_time'   => $data['arrival_time'],
            'departure_time' => $data['departure_time'],
            'stop_sequence'  => (int) $data['stop_sequence'],
        ]);

        return response()->json($stopTime, 201);
    }

    public function claim(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'agency_id' => 'required|string|exists:agencies,agency_id',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);
        Stop::findOrFail($id);

        $claim = AgencyStopClaim::firstOrCreate(
            ['stop_id' => $id, 'agency_id' => $data['agency_id']],
            ['claimed_by' => $request->user()?->id]
        );

        return response()->json($claim, 201);
    }

    public function unclaim(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'agency_id' => 'required|string|exists:agencies,agency_id',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);

        AgencyStopClaim::where('stop_id', $id)
            ->where('agency_id', $data['agency_id'])
            ->delete();

        return response()->json(null, 204);
    }

    public function claimed(Request $request): JsonResponse
    {
        $scope = $this->agencyScope($request);

        $q = AgencyStopClaim::with('stop:id,name,location_t')
            ->when($scope !== null, fn ($q) => $q->whereIn('agency_id', $scope));

        if ($request->filled('agency_id')) {
            $q->where('agency_id', $request->agency_id);
        }

        return response()->json($q->get());
    }
}
