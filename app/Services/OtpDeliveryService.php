<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OtpDeliveryService
{
    /**
     * Deliver the canonical gtfs.zip to the OTP data directory using the configured driver.
     */
    public function deliver(string $zipPath): void
    {
        $driver = config('otp.deploy_driver', 'local');

        match ($driver) {
            'local' => $this->deliverLocal($zipPath),
            'scp'   => $this->deliverScp($zipPath),
            'none'  => Log::info('[OtpDelivery] Driver is "none" — skipping delivery.'),
            default => throw new \RuntimeException("Unknown OTP_DEPLOY_DRIVER: {$driver}"),
        };
    }

    /**
     * Trigger the OTP graph rebuild via the configured shell command.
     * If OTP_BUILD_CMD is empty, this step is skipped gracefully.
     */
    public function rebuild(): void
    {
        $cmd = config('otp.build_cmd', '');

        if (empty($cmd)) {
            Log::info('[OtpDelivery] OTP_BUILD_CMD is empty — skipping graph rebuild.');
            return;
        }

        Log::info("[OtpDelivery] Running build command: {$cmd}");
        $this->runCommand($cmd);
        Log::info('[OtpDelivery] Build command completed successfully.');
    }

    /**
     * Poll the OTP health endpoint until it responds OK.
     * Throws RuntimeException if all retries are exhausted.
     */
    public function waitUntilHealthy(): void
    {
        $url     = config('otp.health_check_url', 'http://127.0.0.1:8080/otp');
        $retries = config('otp.health_check_retries', 30);
        $delay   = config('otp.health_check_delay', 10);

        Log::info("[OtpDelivery] Waiting for OTP health at {$url} (up to {$retries} retries, {$delay}s apart)");

        for ($i = 1; $i <= $retries; $i++) {
            try {
                $response = Http::timeout(5)->get($url);
                if ($response->successful()) {
                    Log::info("[OtpDelivery] OTP is healthy after {$i} attempt(s).");
                    return;
                }
                Log::warning("[OtpDelivery] Attempt {$i}/{$retries}: HTTP {$response->status()}");
            } catch (\Throwable $e) {
                Log::warning("[OtpDelivery] Attempt {$i}/{$retries}: {$e->getMessage()}");
            }

            if ($i < $retries) {
                sleep($delay);
            }
        }

        throw new \RuntimeException("OTP did not become healthy after {$retries} retries ({$url}).");
    }

    private function deliverLocal(string $zipPath): void
    {
        $dataPath = config('otp.data_path', '');

        if (empty($dataPath)) {
            throw new \RuntimeException('OTP_DATA_PATH must be set when OTP_DEPLOY_DRIVER=local.');
        }

        if (!is_dir($dataPath)) {
            throw new \RuntimeException("OTP data directory does not exist: {$dataPath}");
        }

        $dest = rtrim($dataPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'gtfs.zip';

        if (!copy($zipPath, $dest)) {
            throw new \RuntimeException("Failed to copy {$zipPath} to {$dest}");
        }

        Log::info("[OtpDelivery] Delivered gtfs.zip to {$dest}");
    }

    private function deliverScp(string $zipPath): void
    {
        $target = config('otp.scp_target', '');
        $key    = config('otp.scp_key', '');

        if (empty($target)) {
            throw new \RuntimeException('OTP_SCP_TARGET must be set when OTP_DEPLOY_DRIVER=scp.');
        }

        $keyFlag = !empty($key) ? "-i " . escapeshellarg($key) . " " : '';
        $cmd = "scp {$keyFlag}-o StrictHostKeyChecking=no " . escapeshellarg($zipPath) . " " . escapeshellarg($target);

        Log::info("[OtpDelivery] Delivering via SCP to {$target}");
        $this->runCommand($cmd);
        Log::info("[OtpDelivery] SCP delivery complete.");
    }

    private function runCommand(string $cmd): void
    {
        exec($cmd . ' 2>&1', $output, $exitCode);

        $outputStr = implode("\n", $output);

        if (!empty($outputStr)) {
            Log::info("[OtpDelivery] Command output:\n{$outputStr}");
        }

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "Command failed (exit {$exitCode}): {$cmd}\nOutput: {$outputStr}"
            );
        }
    }
}
