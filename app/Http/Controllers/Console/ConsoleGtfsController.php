<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\OtpLog;
use App\Services\Export\ExporterFactory;
use App\Services\GtfsOfficialValidatorService;
use App\Services\GtfsValidatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        // Prevent double-queuing: the UI disables the button while running,
        // but this guard protects against rapid API calls or concurrent sessions.
        // OtpSyncJob sets this key to 'ok' / 'failed' on completion.
        if (Cache::get('otp:sync_status') === 'running') {
            return response()->json(['message' => 'A sync is already in progress.'], 409);
        }

        // Mark as running immediately so a second request hitting the guard above
        // is rejected before the queued job even starts (queue may have a short lag).
        // The job overwrites this with a longer TTL once it begins.
        Cache::put('otp:sync_status', 'running', now()->addMinutes(15));

        // force: true bypasses the 30s debounce — this is an explicit operator action.
        $this->scheduleOtpSync(delaySecs: 0, force: true);

        return response()->json(['message' => 'GTFS export and OTP sync queued.']);
    }

    // ── Feature 24: Official GTFS Validator ───────────────────────────────────

    public function officialValidate(GtfsOfficialValidatorService $validator): JsonResponse
    {
        $cached = Cache::get('quality:official_validation');
        if ($cached) {
            return response()->json($cached);
        }

        if (!$validator->isAvailable()) {
            return response()->json([
                'available' => false,
                'notices'   => [],
                'setup'     => [
                    'instructions' => 'Download the GTFS Validator JAR from https://github.com/MobilityData/gtfs-validator/releases and set GTFS_VALIDATOR_JAR_PATH in your .env file.',
                    'env_vars'     => [
                        'GTFS_VALIDATOR_JAR_PATH' => '/path/to/gtfs-validator.jar',
                        'GTFS_VALIDATOR_JAVA_BIN' => 'java',
                    ],
                ],
            ]);
        }

        $gtfsZipPath = storage_path('app/gtfs/gtfs.zip');
        if (!\Illuminate\Support\Facades\File::exists($gtfsZipPath)) {
            return response()->json(['message' => 'No GTFS export found. Run "Export & Sync" first.'], 422);
        }

        $result = $validator->validate($gtfsZipPath);

        Cache::put('quality:official_validation', $result, now()->addMinutes(30));

        return response()->json($result);
    }

    // ── Feature 25: Multi-Format Export ──────────────────────────────────────

    public function exportAs(Request $request): mixed
    {
        $data   = $request->validate(['format' => 'required|string|in:gtfs,gtfs-flex,excel,netex']);
        $format = $data['format'];

        $exporter = ExporterFactory::make($format);
        $filePath = $exporter->export();

        return response()->download($filePath, $exporter->getFilename(), [
            'Content-Type' => $exporter->getMimeType(),
        ]);
    }
}
