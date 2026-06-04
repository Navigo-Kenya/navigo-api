<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class BulkImportController extends Controller
{
    public function vehicles(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:4096']);

        $agencyId = $request->input('agency_id');
        $this->assertAgencyAllowed($request, $agencyId);

        $dryRun = $request->boolean('dry_run', true);
        $rows   = $this->parseCsv($request->file('file'));

        if (empty($rows)) {
            return response()->json(['error' => 'File is empty or headers could not be parsed.'], 422);
        }

        if (!array_key_exists('plate', $rows[0])) {
            return response()->json([
                'error'    => 'Missing required column: plate',
                'required' => ['plate'],
                'found'    => array_keys($rows[0]),
            ], 422);
        }

        $valid  = [];
        $errors = [];

        foreach ($rows as $i => $row) {
            $rowErrors = [];
            $plate = trim($row['plate'] ?? '');

            if ($plate === '') {
                $rowErrors[] = ['field' => 'plate', 'message' => 'Plate is required'];
            }

            if ($rowErrors) {
                $errors[] = ['row' => $i + 2, 'errors' => $rowErrors, 'data' => $row];
            } else {
                $status = in_array($row['status'] ?? '', ['active', 'inactive', 'suspended'])
                    ? $row['status'] : 'active';

                $valid[] = [
                    'plate'      => strtoupper($plate),
                    'agency_id'  => $agencyId,
                    'model'      => $row['model'] ?? null ?: null,
                    'capacity'   => isset($row['capacity']) && is_numeric($row['capacity'])
                        ? (int) $row['capacity'] : null,
                    'status'     => $status,
                    'route_id'   => $row['route_id'] ?? null ?: null,
                ];
            }
        }

        $imported = 0;
        if (!$dryRun) {
            foreach ($valid as $v) {
                Vehicle::updateOrCreate(['plate' => $v['plate']], $v);
                $imported++;
            }
        }

        return response()->json([
            'dry_run'       => $dryRun,
            'total'         => count($rows),
            'valid'         => count($valid),
            'errors'        => count($errors),
            'imported'      => $imported,
            'error_details' => $errors,
            'valid_preview' => array_slice($valid, 0, 20),
        ]);
    }

    public function drivers(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:4096']);

        $agencyId = $request->input('agency_id');
        $this->assertAgencyAllowed($request, $agencyId);

        $dryRun = $request->boolean('dry_run', true);
        $rows   = $this->parseCsv($request->file('file'));

        if (empty($rows)) {
            return response()->json(['error' => 'File is empty or headers could not be parsed.'], 422);
        }

        if (!array_key_exists('name', $rows[0])) {
            return response()->json([
                'error'    => 'Missing required column: name',
                'required' => ['name'],
                'found'    => array_keys($rows[0]),
            ], 422);
        }

        $valid  = [];
        $errors = [];

        foreach ($rows as $i => $row) {
            $rowErrors = [];
            $name = trim($row['name'] ?? '');

            if ($name === '') {
                $rowErrors[] = ['field' => 'name', 'message' => 'Name is required'];
            }

            if ($rowErrors) {
                $errors[] = ['row' => $i + 2, 'errors' => $rowErrors, 'data' => $row];
            } else {
                $vehicleId = null;
                if (!empty($row['vehicle_plate'])) {
                    $vehicleId = Vehicle::where('plate', strtoupper(trim($row['vehicle_plate'])))
                        ->value('id');
                }

                $valid[] = [
                    'name'       => $name,
                    'phone'      => $row['phone'] ?? null ?: null,
                    'license_no' => $row['license_no'] ?? null ?: null,
                    'vehicle_id' => $vehicleId,
                    'status'     => 'active',
                ];
            }
        }

        $imported = 0;
        if (!$dryRun) {
            foreach ($valid as $v) {
                Driver::create($v);
                $imported++;
            }
        }

        return response()->json([
            'dry_run'       => $dryRun,
            'total'         => count($rows),
            'valid'         => count($valid),
            'errors'        => count($errors),
            'imported'      => $imported,
            'error_details' => $errors,
            'valid_preview' => array_slice($valid, 0, 20),
        ]);
    }

    public function conductors(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:4096']);

        $agencyId = $request->input('agency_id');
        $this->assertAgencyAllowed($request, $agencyId);

        $dryRun = $request->boolean('dry_run', true);
        $rows   = $this->parseCsv($request->file('file'));

        if (empty($rows) || !array_key_exists('name', $rows[0])) {
            return response()->json(['error' => 'Missing required column: name'], 422);
        }

        $valid  = [];
        $errors = [];

        foreach ($rows as $i => $row) {
            $name = trim($row['name'] ?? '');

            if ($name === '') {
                $errors[] = ['row' => $i + 2, 'errors' => [['field' => 'name', 'message' => 'Name is required']], 'data' => $row];
            } else {
                $valid[] = [
                    'agency_id'    => $agencyId,
                    'name'         => $name,
                    'phone'        => $row['phone'] ?? null ?: null,
                    'psv_badge_no' => $row['psv_badge_no'] ?? null ?: null,
                    'status'       => 'active',
                ];
            }
        }

        $imported = 0;
        if (!$dryRun) {
            foreach ($valid as $v) {
                \App\Models\Conductor::create($v);
                $imported++;
            }
        }

        return response()->json([
            'dry_run'       => $dryRun,
            'total'         => count($rows),
            'valid'         => count($valid),
            'errors'        => count($errors),
            'imported'      => $imported,
            'error_details' => $errors,
            'valid_preview' => array_slice($valid, 0, 20),
        ]);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function parseCsv(UploadedFile $file): array
    {
        $rows   = [];
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return [];
        }

        $headers = array_map('trim', $headers);

        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) === count($headers)) {
                $rows[] = array_combine($headers, array_map('trim', $line));
            }
        }

        fclose($handle);

        return $rows;
    }
}
