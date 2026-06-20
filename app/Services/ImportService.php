<?php

namespace App\Services;

use Closure;
use Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

class ImportService
{
    private const TEMP_DIR = 'import-temp';

    /**
     * Store the uploaded file in a temporary location and return a temp_id.
     * The temp_id includes the extension so the parser knows which reader to use.
     */
    public function storeTempFile(UploadedFile $file): string
    {
        $ext    = strtolower($file->getClientOriginalExtension()) ?: 'csv';
        $tempId = Str::uuid()->toString() . '.' . $ext;
        Storage::disk('local')->putFileAs(self::TEMP_DIR, $file, $tempId);

        return $tempId;
    }

    /**
     * Parse the temp file and validate every data row.
     *
     * @param  string   $tempId     Returned by storeTempFile()
     * @param  array    $columnMap  [ 'CSV Header Label' => 'field_name', ... ]  (order matches file columns)
     * @param  Closure  $validator  fn(array $row): string[]  — empty = valid row
     * @return array{temp_id: string, rows: list<array>, total: int, valid_count: int, error_count: int}
     */
    public function preview(string $tempId, array $columnMap, Closure $validator): array
    {
        $rows       = [];
        $validCount = 0;
        $index      = 0;

        foreach ($this->parseFile($tempId, $columnMap) as $row) {
            $index++;
            $errors  = array_values($validator($row) ?? []);
            $isValid = count($errors) === 0;

            if ($isValid) {
                $validCount++;
            }

            $rows[] = [
                'index'    => $index,
                'data'     => $row,
                'errors'   => $errors,
                'is_valid' => $isValid,
            ];
        }

        return [
            'temp_id'     => $tempId,
            'rows'        => $rows,
            'total'       => $index,
            'valid_count' => $validCount,
            'error_count' => $index - $validCount,
        ];
    }

    /**
     * Re-parse the temp file and create records for every valid row.
     * Deletes the temp file on completion regardless of outcome.
     *
     * @param  Closure  $validator  Same closure as preview()
     * @param  Closure  $creator    fn(array $row): void — throws on failure
     * @return array{imported: int, failed: int, errors: list<array{row: int, message: string}>}
     */
    public function confirm(string $tempId, array $columnMap, Closure $validator, Closure $creator): array
    {
        $imported = 0;
        $failed   = 0;
        $errors   = [];
        $index    = 0;

        foreach ($this->parseFile($tempId, $columnMap) as $row) {
            $index++;
            $rowErrors = $validator($row) ?? [];

            if (count($rowErrors) > 0) {
                $failed++;
                $errors[] = ['row' => $index, 'message' => implode('; ', $rowErrors)];
                continue;
            }

            try {
                $creator($row);
                $imported++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['row' => $index, 'message' => $e->getMessage()];
            }
        }

        Storage::disk('local')->delete(self::TEMP_DIR . '/' . $tempId);

        return ['imported' => $imported, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Generate a sample CSV string (headers + optional example rows).
     *
     * @param  string[] $headers
     * @param  array[]  $sampleRows  Each element is an ordered array of cell values
     */
    public function sampleCsvContent(array $headers, array $sampleRows = []): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);

        foreach ($sampleRows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return (string) $content;
    }

    /**
     * Lazily parse a CSV or XLSX file, yielding each data row as an associative
     * array keyed by the field names defined in $columnMap.
     * The first row is treated as headers and matched case-insensitively.
     * Completely empty rows are skipped.
     * Only the first sheet is processed.
     */
    private function parseFile(string $tempId, array $columnMap): Generator
    {
        $path = Storage::disk('local')->path(self::TEMP_DIR . '/' . $tempId);
        $ext  = strtolower(pathinfo($tempId, PATHINFO_EXTENSION));

        $reader = $ext === 'xlsx' ? new XlsxReader() : new CsvReader();
        $reader->open($path);

        // Build a normalized map: lowercase_header => field_name
        $normalMap = [];
        foreach ($columnMap as $header => $field) {
            $normalMap[strtolower(trim($header))] = $field;
        }

        foreach ($reader->getSheetIterator() as $sheet) {
            $headerIndex = null; // col index => field name

            foreach ($sheet->getRowIterator() as $row) {
                $values = array_map(fn ($v) => trim((string) $v), $row->toArray());

                if ($headerIndex === null) {
                    $headerIndex = [];
                    foreach ($values as $i => $label) {
                        $key = strtolower(trim($label));
                        if (isset($normalMap[$key])) {
                            $headerIndex[$i] = $normalMap[$key];
                        }
                    }
                    continue;
                }

                // Skip blank rows
                if (count(array_filter($values, fn ($v) => $v !== '')) === 0) {
                    continue;
                }

                $assoc = array_fill_keys(array_values($columnMap), '');

                foreach ($headerIndex as $colIdx => $field) {
                    $assoc[$field] = $values[$colIdx] ?? '';
                }

                yield $assoc;
            }

            break; // first sheet only
        }

        $reader->close();
    }
}
