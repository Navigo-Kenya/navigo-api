<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Jobs\OtpSyncJob;
use App\Models\OtpLog;
use App\Services\GtfsValidatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ConsoleGtfsController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json([
            'sync_status'       => Cache::get('otp:sync_status', 'unknown'),
            'last_synced_at'    => Cache::get('otp:last_synced_at'),
            'validation_errors' => Cache::get('otp:validation_errors'),
        ]);
    }

    public function validate(GtfsValidatorService $validator): JsonResponse
    {
        $result = $validator->validate();

        Cache::put('otp:validation_errors', $result->toArray(), now()->addHour());

        OtpLog::create([
            'event'   => 'validate',
            'status'  => $result->valid ? 'success' : 'failed',
            'message' => $result->valid
                ? 'All GTFS checks passed.'
                : \count($result->errors) . ' error(s) found. ' . \count($result->warnings) . ' warning(s).',
        ]);

        return response()->json($result->toArray());
    }

    public function export(): JsonResponse
    {
        Cache::put('otp:sync_status', 'running', now()->addMinutes(15));
        OtpSyncJob::dispatch()->onQueue('otp');

        return response()->json(['message' => 'GTFS export and OTP sync queued.']);
    }
}
