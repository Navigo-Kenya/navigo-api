<?php

namespace App\Services\Export;

use App\Services\GtfsExportService;

class GtfsExporter implements ExporterContract
{
    public function __construct(private GtfsExportService $service) {}

    public function export(): string
    {
        return $this->service->export();
    }

    public function getMimeType(): string
    {
        return 'application/zip';
    }

    public function getFilename(): string
    {
        return 'gtfs.zip';
    }
}
