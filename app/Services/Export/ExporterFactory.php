<?php

namespace App\Services\Export;

use App\Services\GtfsExportService;
use InvalidArgumentException;

class ExporterFactory
{
    public static function make(string $format): ExporterContract
    {
        $gtfsService = app(GtfsExportService::class);

        return match ($format) {
            'gtfs'      => new GtfsExporter($gtfsService),
            'gtfs-flex' => new GtfsFlexExporter($gtfsService),
            'excel'     => new ExcelExporter(),
            'netex'     => new NeTExExporter(),
            default     => throw new InvalidArgumentException("Unknown export format: {$format}"),
        };
    }
}
