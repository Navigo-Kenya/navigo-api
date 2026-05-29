<?php

namespace App\Services\Export;

use App\Models\Stop;
use App\Services\GtfsExportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class GtfsFlexExporter implements ExporterContract
{
    public function __construct(private GtfsExportService $service) {}

    public function export(): string
    {
        // First export the standard GTFS zip
        $gtfsZipPath = $this->service->export();

        // Build locations.geojson from all stops
        $stops = DB::table('stops')
            ->selectRaw("id, name, ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng")
            ->get();

        $features = $stops->map(fn ($s) => [
            'type' => 'Feature',
            'id'   => $s->id,
            'geometry' => [
                'type'        => 'Point',
                'coordinates' => [(float) $s->lng, (float) $s->lat],
            ],
            'properties' => [
                'stop_id'   => $s->id,
                'stop_name' => $s->name,
            ],
        ])->values()->toArray();

        $geojson = json_encode([
            'type'     => 'FeatureCollection',
            'features' => $features,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Add locations.geojson into a copy of the zip
        $storageDir = storage_path('app/gtfs');
        $flexPath   = $storageDir . DIRECTORY_SEPARATOR . 'gtfs_flex_' . now()->format('Ymd_His') . '.zip';

        File::copy($gtfsZipPath, $flexPath);

        $zip = new \ZipArchive();
        if ($zip->open($flexPath) !== true) {
            throw new \RuntimeException("Cannot open GTFS-Flex zip for modification.");
        }
        $zip->addFromString('locations.geojson', $geojson);
        $zip->close();

        return $flexPath;
    }

    public function getMimeType(): string
    {
        return 'application/zip';
    }

    public function getFilename(): string
    {
        return 'gtfs-flex.zip';
    }
}
