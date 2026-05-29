<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class GtfsOfficialValidatorService
{
    private string $jarPath;
    private string $javaBin;

    public function __construct()
    {
        $this->jarPath = config('gtfs_validator.jar_path', '');
        $this->javaBin = config('gtfs_validator.java_bin', 'java');
    }

    public function isAvailable(): bool
    {
        if (empty($this->jarPath) || !File::exists($this->jarPath)) {
            return false;
        }

        exec($this->javaBin . ' -version 2>&1', $out, $code);
        return $code === 0;
    }

    public function validate(string $zipPath): array
    {
        if (!$this->isAvailable()) {
            return ['available' => false, 'notices' => []];
        }

        $outputDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gtfs_validator_' . uniqid();
        File::makeDirectory($outputDir, 0755, true);

        try {
            $jarPath    = escapeshellarg($this->jarPath);
            $zipPathEsc = escapeshellarg($zipPath);
            $outputEsc  = escapeshellarg($outputDir);
            $javaBin    = escapeshellarg($this->javaBin);

            $cmd = "{$javaBin} -jar {$jarPath} --input {$zipPathEsc} --output_base {$outputEsc} 2>&1";
            exec($cmd, $output, $exitCode);

            $reportPath = $outputDir . DIRECTORY_SEPARATOR . 'report.json';

            if (!File::exists($reportPath)) {
                Log::warning('GtfsOfficialValidatorService: report.json not found', [
                    'exit_code' => $exitCode,
                    'output'    => implode("\n", array_slice($output, -10)),
                ]);
                return [
                    'available'    => true,
                    'notices'      => [],
                    'error'        => 'Validation produced no report. Check Java and JAR configuration.',
                    'validated_at' => now()->toIso8601String(),
                ];
            }

            $report  = json_decode(File::get($reportPath), true);
            $notices = $this->parseReport($report);

            return [
                'available'    => true,
                'notices'      => $notices,
                'validated_at' => now()->toIso8601String(),
            ];
        } finally {
            File::deleteDirectory($outputDir);
        }
    }

    private function parseReport(array $report): array
    {
        // The GTFS validator report.json groups notices under 'notices' array
        $rawNotices = $report['notices'] ?? [];
        $result     = [];

        foreach ($rawNotices as $notice) {
            $code     = $notice['code']     ?? $notice['noticeCode'] ?? 'UNKNOWN';
            $severity = $this->normalizeSeverity($notice['severity'] ?? $notice['totalNotices'] ?? 'WARNING');
            $samples  = [];

            foreach (array_slice($notice['sampleNotices'] ?? [], 0, 3) as $sample) {
                $samples[] = [
                    'message'  => $sample['message'] ?? json_encode($sample),
                    'entities' => $sample['fieldValues'] ?? $sample['entityIds'] ?? [],
                ];
            }

            $result[] = [
                'code'          => $code,
                'severity'      => $severity,
                'totalNotices'  => (int) ($notice['totalNotices'] ?? 1),
                'sampleNotices' => $samples,
            ];
        }

        // Sort by severity: ERROR first, then WARNING, then INFO
        $order = ['ERROR' => 0, 'WARNING' => 1, 'INFO' => 2];
        usort($result, fn ($a, $b) => ($order[$a['severity']] ?? 3) <=> ($order[$b['severity']] ?? 3));

        return $result;
    }

    private function normalizeSeverity(mixed $raw): string
    {
        $str = strtoupper((string) $raw);
        return match (true) {
            str_contains($str, 'ERROR')   => 'ERROR',
            str_contains($str, 'WARNING') => 'WARNING',
            default                       => 'INFO',
        };
    }
}
