<?php

namespace App\Services\AI;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Shared service-account OAuth2 token management for Google Cloud APIs.
 *
 * Tokens are cached for 50 minutes (tokens last 60 min; 10-min buffer for clock skew).
 * Any class that uses this trait gets a single `getAccessToken()` method.
 */
trait GoogleCloudAuth
{
    private function getAccessToken(): string
    {
        $keyPath = $this->resolvedKeyPath();

        // Cache key includes a hash of the key file so credential rotation invalidates it
        $cacheKey = 'gcp_access_token:' . md5($keyPath);

        return Cache::remember($cacheKey, 3000, function () use ($keyPath) {
            if (!file_exists($keyPath)) {
                throw new \RuntimeException("GCP service account key not found: {$keyPath}");
            }

            $creds = new ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/cloud-platform'],
                $keyPath
            );

            $token = $creds->fetchAuthToken();

            if (empty($token['access_token'])) {
                throw new \RuntimeException('GCP fetchAuthToken returned no access_token.');
            }

            return $token['access_token'];
        });
    }

    private function resolvedKeyPath(): string
    {
        $configured = config('services.google_cloud.key_path', 'storage/app/gcp-key.json');

        // If absolute path, use as-is; otherwise resolve relative to Laravel base
        return str_starts_with($configured, '/')
            ? $configured
            : base_path($configured);
    }
}
