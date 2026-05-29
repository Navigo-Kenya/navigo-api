<?php

namespace App\Services\Export;

interface ExporterContract
{
    public function export(): string;
    public function getMimeType(): string;
    public function getFilename(): string;
}
