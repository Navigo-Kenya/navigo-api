<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Batched navigation telemetry from the app (reroute rate, snap rate,
 * arrival precision, GPS-lost duration...). Guest-friendly and fire-and-
 * forget: this data is how thresholds like ARRIVE_M get tuned.
 */
class NavMetricsController extends Controller
{
    private const ALLOWED_EVENTS = [
        'reroute', 'gps_lost', 'snap_rate', 'arrival_precision',
        'wrong_direction', 'board_manual', 'board_auto', 'session_summary',
    ];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id'       => ['required', 'uuid'],
            'device'           => ['nullable', 'string', 'max:80'],
            'events'           => ['required', 'array', 'max:100'],
            'events.*.event'   => ['required', 'string', 'in:' . implode(',', self::ALLOWED_EVENTS)],
            'events.*.value'   => ['nullable', 'numeric'],
            'events.*.meta'    => ['nullable', 'array'],
            'events.*.at'      => ['nullable', 'integer'], // epoch ms
        ]);

        $userId = auth('sanctum')->user()?->getAuthIdentifier();
        $device = $validated['device'] ?? null;

        $rows = array_map(fn (array $e) => [
            'session_id' => $validated['session_id'],
            'user_id'    => $userId,
            'event'      => $e['event'],
            'value'      => $e['value'] ?? null,
            'meta'       => isset($e['meta']) ? json_encode($e['meta']) : null,
            'device'     => $device,
            'created_at' => isset($e['at'])
                ? date('Y-m-d H:i:s', (int) ($e['at'] / 1000))
                : now()->toDateTimeString(),
        ], $validated['events']);

        DB::table('nav_metrics')->insert($rows);

        return response()->json(['stored' => count($rows)], 201);
    }
}
