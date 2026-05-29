<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\InteropEntry;
use App\Models\Level;
use App\Models\Pathway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsoleInteropController extends Controller
{
    // ── Interop Entries ───────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = "
            SELECT id, name, type, description, gtfs_stop_id, connections,
                   ST_Y(location::geometry) AS lat,
                   ST_X(location::geometry) AS lng
            FROM interop_entries
        ";

        $bindings = [];

        if ($request->filled('bounds')) {
            // bounds=sw_lat,sw_lng,ne_lat,ne_lng
            $parts = explode(',', $request->query('bounds'));
            if (count($parts) === 4) {
                [$swLat, $swLng, $neLat, $neLng] = array_map('floatval', $parts);
                $query    .= " WHERE ST_Within(location::geometry, ST_MakeEnvelope(?, ?, ?, ?, 4326))";
                $bindings  = [$swLng, $swLat, $neLng, $neLat];
            }
        }

        if ($request->filled('type')) {
            $hasWhere  = str_contains($query, 'WHERE');
            $query    .= ($hasWhere ? ' AND' : ' WHERE') . ' type = ?';
            $bindings[] = $request->query('type');
        }

        $query .= ' ORDER BY name';
        $rows   = DB::select($query, $bindings);

        return response()->json(array_map(fn ($r) => [
            'id'           => $r->id,
            'name'         => $r->name,
            'type'         => $r->type,
            'lat'          => (float) $r->lat,
            'lng'          => (float) $r->lng,
            'description'  => $r->description,
            'gtfs_stop_id' => $r->gtfs_stop_id,
            'connections'  => $r->connections ? json_decode($r->connections, true) : null,
        ], $rows));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'type'         => 'required|in:bikeshare,park_and_ride,taxi_rank,airport_terminal,ferry_terminal,brt_station,rail_station',
            'lat'          => 'required|numeric|between:-90,90',
            'lng'          => 'required|numeric|between:-180,180',
            'description'  => 'nullable|string',
            'gtfs_stop_id' => 'nullable|string|exists:stops,id',
            'connections'  => 'nullable|array',
        ]);

        DB::statement("
            INSERT INTO interop_entries
                (name, type, location, description, gtfs_stop_id, connections, created_at, updated_at)
            VALUES (?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326), ?, ?, ?, NOW(), NOW())
        ", [
            $data['name'],
            $data['type'],
            $data['lng'],
            $data['lat'],
            $data['description'] ?? null,
            $data['gtfs_stop_id'] ?? null,
            isset($data['connections']) ? json_encode($data['connections']) : null,
        ]);

        $entry = DB::selectOne("
            SELECT id, name, type, description, gtfs_stop_id, connections,
                   ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng
            FROM interop_entries ORDER BY id DESC LIMIT 1
        ");

        return response()->json($this->formatEntry($entry), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'sometimes|string|max:255',
            'type'         => 'sometimes|in:bikeshare,park_and_ride,taxi_rank,airport_terminal,ferry_terminal,brt_station,rail_station',
            'lat'          => 'sometimes|numeric|between:-90,90',
            'lng'          => 'sometimes|numeric|between:-180,180',
            'description'  => 'nullable|string',
            'gtfs_stop_id' => 'nullable|string|exists:stops,id',
            'connections'  => 'nullable|array',
        ]);

        $hasLocation = isset($data['lat']) && isset($data['lng']);

        if ($hasLocation) {
            DB::statement("
                UPDATE interop_entries SET
                    name         = COALESCE(?, name),
                    type         = COALESCE(?, type),
                    location     = ST_SetSRID(ST_MakePoint(?, ?), 4326),
                    description  = ?,
                    gtfs_stop_id = ?,
                    connections  = COALESCE(?, connections),
                    updated_at   = NOW()
                WHERE id = ?
            ", [
                $data['name'] ?? null, $data['type'] ?? null,
                $data['lng'], $data['lat'],
                $data['description'] ?? null,
                array_key_exists('gtfs_stop_id', $data) ? $data['gtfs_stop_id'] : DB::raw('gtfs_stop_id'),
                isset($data['connections']) ? json_encode($data['connections']) : null,
                $id,
            ]);
        } else {
            DB::statement("
                UPDATE interop_entries SET
                    name         = COALESCE(?, name),
                    type         = COALESCE(?, type),
                    description  = COALESCE(?, description),
                    gtfs_stop_id = COALESCE(?, gtfs_stop_id),
                    connections  = COALESCE(?, connections),
                    updated_at   = NOW()
                WHERE id = ?
            ", [
                $data['name'] ?? null,
                $data['type'] ?? null,
                $data['description'] ?? null,
                $data['gtfs_stop_id'] ?? null,
                isset($data['connections']) ? json_encode($data['connections']) : null,
                $id,
            ]);
        }

        $entry = DB::selectOne("
            SELECT id, name, type, description, gtfs_stop_id, connections,
                   ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng
            FROM interop_entries WHERE id = ?
        ", [$id]);

        return response()->json($this->formatEntry($entry));
    }

    public function destroy(int $id): JsonResponse
    {
        InteropEntry::findOrFail($id)->delete();
        return response()->json(['message' => 'Interop entry deleted.']);
    }

    // ── Levels ────────────────────────────────────────────────────────────────

    public function levels(Request $request): JsonResponse
    {
        $query = Level::query()->orderBy('level_index');

        if ($request->filled('stop_id')) {
            $query->where('stop_id', $request->query('stop_id'));
        }

        return response()->json($query->get());
    }

    public function storeLevel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'level_id'    => 'nullable|string|max:255|unique:levels,level_id',
            'level_index' => 'required|numeric',
            'level_name'  => 'required|string|max:255',
            'stop_id'     => 'required|string|exists:stops,id',
        ]);

        $data['level_id'] = $data['level_id'] ?? Str::upper(Str::random(6)) . '_L' . (int) abs($data['level_index'] * 10);

        $level = Level::create($data);
        return response()->json($level, 201);
    }

    public function updateLevel(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'level_index' => 'sometimes|numeric',
            'level_name'  => 'sometimes|string|max:255',
        ]);

        $level = Level::findOrFail($id);
        $level->update($data);

        return response()->json($level);
    }

    public function destroyLevel(int $id): JsonResponse
    {
        Level::findOrFail($id)->delete();
        return response()->json(['message' => 'Level deleted.']);
    }

    // ── Pathways ──────────────────────────────────────────────────────────────

    public function pathways(Request $request): JsonResponse
    {
        $query = Pathway::with(['fromStop:id,name', 'toStop:id,name'])->orderBy('id');

        if ($request->filled('stop_id')) {
            $stopId = $request->query('stop_id');
            $query->where(function ($q) use ($stopId) {
                $q->where('from_stop_id', $stopId)->orWhere('to_stop_id', $stopId);
            });
        }

        return response()->json($query->get());
    }

    public function storePathway(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pathway_id'            => 'nullable|string|max:255|unique:pathways,pathway_id',
            'from_stop_id'          => 'required|string|exists:stops,id',
            'to_stop_id'            => 'required|string|exists:stops,id',
            'pathway_mode'          => 'required|integer|in:1,2,3,4,5,6,7',
            'is_bidirectional'      => 'sometimes|boolean',
            'length'                => 'nullable|numeric|min:0',
            'traversal_time'        => 'nullable|integer|min:0',
            'stair_count'           => 'nullable|integer',
            'max_slope'             => 'nullable|numeric',
            'min_width'             => 'nullable|numeric|min:0',
            'signposted_as'         => 'nullable|string|max:255',
            'reversed_signposted_as'=> 'nullable|string|max:255',
        ]);

        $data['pathway_id']       = $data['pathway_id'] ?? 'PATH_' . Str::upper(Str::random(8));
        $data['is_bidirectional'] = $data['is_bidirectional'] ?? true;

        $pathway = Pathway::create($data);
        return response()->json($pathway->load(['fromStop:id,name', 'toStop:id,name']), 201);
    }

    public function updatePathway(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'pathway_mode'          => 'sometimes|integer|in:1,2,3,4,5,6,7',
            'is_bidirectional'      => 'sometimes|boolean',
            'length'                => 'nullable|numeric|min:0',
            'traversal_time'        => 'nullable|integer|min:0',
            'stair_count'           => 'nullable|integer',
            'max_slope'             => 'nullable|numeric',
            'min_width'             => 'nullable|numeric|min:0',
            'signposted_as'         => 'nullable|string|max:255',
            'reversed_signposted_as'=> 'nullable|string|max:255',
        ]);

        $pathway = Pathway::findOrFail($id);
        $pathway->update($data);

        return response()->json($pathway->load(['fromStop:id,name', 'toStop:id,name']));
    }

    public function destroyPathway(int $id): JsonResponse
    {
        Pathway::findOrFail($id)->delete();
        return response()->json(['message' => 'Pathway deleted.']);
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public function exportPathwayFiles(): Response
    {
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pathways_' . uniqid();
        mkdir($tmpDir, 0755, true);

        $this->writePathwaysCsv($tmpDir);
        $this->writeLevelsCsv($tmpDir);

        $zipPath = $tmpDir . DIRECTORY_SEPARATOR . 'pathways_gtfs.zip';
        $zip     = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFile($tmpDir . DIRECTORY_SEPARATOR . 'pathways.txt', 'pathways.txt');
        $zip->addFile($tmpDir . DIRECTORY_SEPARATOR . 'levels.txt', 'levels.txt');
        $zip->close();

        $content = file_get_contents($zipPath);
        array_map('unlink', glob($tmpDir . DIRECTORY_SEPARATOR . '*'));
        rmdir($tmpDir);

        return response($content, 200, [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => 'attachment; filename="pathways_gtfs.zip"',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatEntry(object $entry): array
    {
        return [
            'id'           => $entry->id,
            'name'         => $entry->name,
            'type'         => $entry->type,
            'lat'          => (float) $entry->lat,
            'lng'          => (float) $entry->lng,
            'description'  => $entry->description,
            'gtfs_stop_id' => $entry->gtfs_stop_id,
            'connections'  => $entry->connections ? json_decode($entry->connections, true) : null,
        ];
    }

    private function writePathwaysCsv(string $dir): void
    {
        $handle = fopen($dir . DIRECTORY_SEPARATOR . 'pathways.txt', 'w');
        fputcsv($handle, [
            'pathway_id', 'from_stop_id', 'to_stop_id', 'pathway_mode',
            'is_bidirectional', 'length', 'traversal_time', 'stair_count',
            'max_slope', 'min_width', 'signposted_as', 'reversed_signposted_as',
        ]);
        Pathway::all()->each(function ($p) use ($handle) {
            fputcsv($handle, [
                $p->pathway_id,
                $p->from_stop_id,
                $p->to_stop_id,
                $p->pathway_mode,
                $p->is_bidirectional ? 1 : 0,
                $p->length ?? '',
                $p->traversal_time ?? '',
                $p->stair_count ?? '',
                $p->max_slope ?? '',
                $p->min_width ?? '',
                $p->signposted_as ?? '',
                $p->reversed_signposted_as ?? '',
            ]);
        });
        fclose($handle);
    }

    private function writeLevelsCsv(string $dir): void
    {
        $handle = fopen($dir . DIRECTORY_SEPARATOR . 'levels.txt', 'w');
        fputcsv($handle, ['level_id', 'level_index', 'level_name', 'parent_station']);
        Level::all()->each(function ($l) use ($handle) {
            fputcsv($handle, [
                $l->level_id,
                $l->level_index,
                $l->level_name,
                $l->stop_id,
            ]);
        });
        fclose($handle);
    }
}
