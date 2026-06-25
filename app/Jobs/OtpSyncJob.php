<?php

namespace App\Jobs;

use App\Models\OtpLog;
use App\Services\GtfsExportService;
use App\Services\GtfsValidatorService;
use App\Services\OtpDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OtpSyncJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue;

    // Graph rebuild (3-8 min) + OTP load (1-3 min) + health polling (up to 5 min) = allow 20 min
    public int $timeout = 1200;
    // Do not auto-retry, rebuild failures need human inspection
    public int $tries = 1;

    public function handle(
        GtfsExportService  $gtfs,
        GtfsValidatorService $validator,
        OtpDeliveryService $delivery
    ): void {
        $startedAt = now();

        Cache::forget('otp:cancel_requested');
        Cache::put('otp:sync_status', 'running', now()->addSeconds($this->timeout + 30));

        OtpLog::create([
            'event'   => 'gtfs_build',
            'status'  => 'running',
            'message' => 'GTFS export and OTP sync started.',
        ]);

        try {
            // Step 1, Pre-export GTFS validation gate
            $this->checkCancelled();
            $validation = $validator->validate();
            Cache::put('otp:validation_errors', $validation->toArray(), now()->addHour());

            if (!$validation->valid) {
                $errorCount = \count($validation->errors);
                Log::error("[OtpSync] Aborting: {$errorCount} validation error(s).");
                Cache::put('otp:sync_status', 'validation_failed', now()->addHour());

                OtpLog::create([
                    'event'   => 'gtfs_build',
                    'status'  => 'failed',
                    'message' => "Aborting sync, {$errorCount} GTFS validation error(s). Fix errors and retry.",
                ]);

                $this->fail(new \RuntimeException("GTFS validation failed with {$errorCount} error(s)."));
                return;
            }

            // Step 2, Export GTFS files and produce a zip
            $this->checkCancelled();

            OtpLog::create([
                'event'   => 'gtfs_export',
                'status'  => 'running',
                'message' => 'Exporting GTFS data to zip archive.',
            ]);

            $zipPath = $gtfs->export();

            OtpLog::create([
                'event'   => 'gtfs_export',
                'status'  => 'success',
                'message' => "GTFS zip created at {$zipPath}",
            ]);

            // Step 3, Deliver zip to OTP data directory
            $this->checkCancelled();
            $driver = config('otp.deploy_driver', 'local');

            OtpLog::create([
                'event'   => 'otp_deliver',
                'status'  => 'running',
                'message' => "Delivering gtfs.zip via '{$driver}' driver.",
            ]);

            $delivery->deliver($zipPath);

            OtpLog::create([
                'event'   => 'otp_deliver',
                'status'  => 'success',
                'message' => "gtfs.zip delivered via '{$driver}' driver.",
            ]);

            // Step 4, Trigger graph rebuild
            $this->checkCancelled();

            OtpLog::create([
                'event'   => 'otp_build',
                'status'  => 'running',
                'message' => 'Triggering OTP graph rebuild.',
            ]);

            $delivery->rebuild();

            OtpLog::create([
                'event'   => 'otp_build',
                'status'  => 'success',
                'message' => 'OTP graph rebuild completed.',
            ]);

            // Step 5, Wait for OTP to be healthy
            $this->checkCancelled();

            OtpLog::create([
                'event'   => 'otp_sync',
                'status'  => 'running',
                'message' => 'Polling OTP health endpoint.',
            ]);

            $delivery->waitUntilHealthy();

            OtpLog::create([
                'event'   => 'otp_sync',
                'status'  => 'success',
                'message' => 'OTP is healthy and serving requests.',
            ]);

            // Flush stale journey cache so the first post-rebuild API calls
            // hit the new graph instead of returning old cached routes.
            $redis   = \Illuminate\Support\Facades\Redis::connection();
            $cursor  = '0';
            $flushed = 0;
            do {
                [$cursor, $keys] = $redis->scan($cursor, 'MATCH', '*otp:journey:v2:*', 'COUNT', 200);
                if (!empty($keys)) {
                    $redis->del(...$keys);
                    $flushed += count($keys);
                }
            } while ($cursor !== '0');
            if ($flushed > 0) {
                Log::info("[OtpSync] Flushed {$flushed} stale journey cache entries.");
            }

            // Step 6, Finalise
            $duration = now()->diffInSeconds($startedAt);

            Cache::put('otp:last_sync',      now()->toIso8601String(), now()->addYear());
            Cache::put('otp:last_synced_at', now()->toIso8601String(), now()->addYear());
            Cache::put('otp:last_duration',  $duration,                now()->addYear());
            Cache::put('otp:sync_status',    'ok',                     now()->addYear());
            Cache::forget('otp:sync_error');

            Log::info("[OtpSync] Completed in {$duration}s");

            OtpLog::create([
                'event'   => 'gtfs_build',
                'status'  => 'success',
                'message' => "Full sync completed in {$duration}s.",
            ]);
        } catch (\Throwable $e) {
            Cache::put('otp:sync_status', 'failed',         now()->addHour());
            Cache::put('otp:sync_error',  $e->getMessage(), now()->addHour());
            Log::error('[OtpSync] Failed: ' . $e->getMessage());

            OtpLog::create([
                'event'   => 'gtfs_build',
                'status'  => 'failed',
                'message' => 'Sync failed: ' . $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function checkCancelled(): void
    {
        if (!Cache::get('otp:cancel_requested')) {
            return;
        }

        Cache::forget('otp:cancel_requested');
        Cache::put('otp:sync_status', 'failed', now()->addHour());

        OtpLog::create([
            'event'   => 'cancel',
            'status'  => 'failed',
            'message' => 'Job cancelled by user.',
        ]);

        throw new \RuntimeException('Job cancelled by user.');
    }
}
