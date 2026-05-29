<?php

namespace App\Services\Export;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class ExcelExporter implements ExporterContract
{
    public function export(): string
    {
        $storageDir = storage_path('app/gtfs');
        File::ensureDirectoryExists($storageDir);
        $path = $storageDir . DIRECTORY_SEPARATOR . 'hopln_' . now()->format('Ymd_His') . '.xlsx';

        $writer = new Writer();
        $writer->openToFile($path);

        $this->writeSheet($writer, 'agencies', ['agency_id', 'agency_name', 'agency_url', 'agency_timezone'],
            DB::table('agencies')->get()->toArray()
        );

        $this->writeSheet($writer, 'routes', ['route_id', 'route_short_name', 'route_long_name', 'route_type', 'route_color'],
            DB::table('routes')->get()->toArray()
        );

        $this->writeSheet($writer, 'trips', ['trip_id', 'route_id', 'service_id', 'trip_headsign', 'direction_id', 'block_id', 'shape_id'],
            DB::table('trips')->limit(50000)->get()->toArray()
        );

        $this->writeSheet($writer, 'stops',
            ['id', 'name', 'lat', 'lng'],
            DB::table('stops')
                ->selectRaw("id, name, ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng")
                ->get()
                ->toArray()
        );

        $this->writeSheet($writer, 'stop_times',
            ['trip_id', 'stop_id', 'arrival_time', 'departure_time', 'stop_sequence'],
            DB::table('stop_times')->limit(200000)->get()->toArray(),
            false
        );

        $writer->close();

        return $path;
    }

    private function writeSheet(Writer $writer, string $name, array $headers, array $rows, bool $addNext = true): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName($name);

        $writer->addRow(Row::fromValues($headers));

        foreach ($rows as $row) {
            $data = [];
            foreach ($headers as $col) {
                $data[] = is_object($row) ? ($row->$col ?? '') : ($row[$col] ?? '');
            }
            $writer->addRow(Row::fromValues($data));
        }

        if ($addNext) {
            $writer->addNewSheetAndMakeItCurrent();
        }
    }

    public function getMimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function getFilename(): string
    {
        return 'hopln_export.xlsx';
    }
}
