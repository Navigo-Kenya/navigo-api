<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\LogJourneyJob;
use App\Services\AiAssistantService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiTransitController extends Controller
{
    public function __construct(
        protected AiAssistantService $aiService
    ) {}

    public function planRouteWithAi(Request $request): JsonResponse
    {
        set_time_limit(60);

        $validated = $request->validate([
            'session_id'                     => ['nullable', 'string', 'max:255'],
            'text'                           => ['nullable', 'string', 'max:1000'],
            'audio'                          => ['nullable', 'array'],
            'audio.base64'                   => ['nullable', 'string'],
            'audio.mime'                     => ['nullable', 'string', 'max:50'],
            'lat'                            => ['nullable', 'numeric', 'between:-90,90'],
            'lng'                            => ['nullable', 'numeric', 'between:-180,180'],
            'aliases'                        => ['nullable', 'array'],
            'voice_settings'                 => ['nullable', 'array'],
            'voice_settings.voice_name'      => ['nullable', 'string', 'max:50'],
            'voice_settings.speaking_rate'   => ['nullable', 'numeric', 'between:0.25,4.0'],
            'voice_settings.pitch'           => ['nullable', 'numeric', 'between:-20.0,20.0'],
            'voice_settings.language_code'   => ['nullable', 'string', 'max:20'],
            'voice_settings.response_style'  => ['nullable', 'string', 'in:casual,professional,brief'],
            'context'                        => ['nullable', 'array'],
            'context.calendar_events'        => ['nullable', 'array', 'max:10'],
            'context.calendar_events.*.title'    => ['nullable', 'string', 'max:120'],
            'context.calendar_events.*.start'    => ['nullable', 'string', 'max:60'],
            'context.calendar_events.*.location' => ['nullable', 'string', 'max:200'],
            'context.nav'                        => ['nullable', 'array'],
            'context.nav.trip_status'            => ['nullable', 'string', 'max:20'],
            'context.nav.destination'            => ['nullable', 'string', 'max:150'],
            'context.nav.next_instruction'       => ['nullable', 'string', 'max:200'],
            'context.nav.segment_mode'           => ['nullable', 'string', 'max:20'],
            'context.nav.current_line'           => ['nullable', 'string', 'max:30'],
            'context.nav.stops_remaining'        => ['nullable', 'integer', 'min:0', 'max:200'],
            'context.nav.current_stop'           => ['nullable', 'string', 'max:150'],
            'context.nav.remaining_m'            => ['nullable', 'numeric', 'min:0'],
            'context.nav.eta'                    => ['nullable', 'string', 'max:30'],
            // Vision: accepted but ignored until KWAME_VISION_ENABLED=true (needs SACCO livery dataset).
            'image'                              => ['nullable', 'array'],
            'image.base64'                       => ['nullable', 'string', 'max:2000000'],
            'image.mime'                         => ['nullable', 'string', 'in:image/jpeg,image/png,image/webp'],
        ]);

        $sessionId = $validated['session_id'] ?? 'default';
        $audioFile = $validated['audio'] ?? null;

        try {
            $result = $this->aiService->chat(
                sessionId:     $sessionId,
                text:          $validated['text'] ?? null,
                audioFile:     $audioFile,
                userLat:       $validated['lat'] ?? null,
                userLng:       $validated['lng'] ?? null,
                aliases:       $validated['aliases'] ?? [],
                voiceSettings: $validated['voice_settings'] ?? null,
                context:       $validated['context'] ?? null,
            );

            if (!$result) {
                // Could be OTP down or AI parsing failure — surface a clear message either way.
                return response()->json([
                    'message' => 'Route planning is temporarily unavailable. Please try again in a moment.',
                    'code'    => 'OTP_UNAVAILABLE',
                ], 503);
            }
        } catch (Throwable $e) {
            Log::error('AI Transit processing failed', [
                'session_id' => $sessionId,
                'exception'  => $e->getMessage(),
                'class'      => get_class($e),
                'file'       => $e->getFile() . ':' . $e->getLine(),
                'trace'      => $e->getTraceAsString(),
            ]);

            $isConfigError = str_contains($e->getMessage(), 'GCP_PROJECT_ID') || str_contains($e->getMessage(), 'GEMINI_API_KEY');

            if (app()->isLocal() || app()->environment('staging')) {
                return response()->json([
                    'error'   => $e->getMessage(),
                    'class'   => get_class($e),
                    'hint'    => $isConfigError ? 'Check GCP_PROJECT_ID and GCP_KEY_PATH in your .env file.' : null,
                ], 500);
            }

            return response()->json([
                'error' => $isConfigError
                    ? 'AI routing is not configured on this server.'
                    : 'An upstream scheduling error occurred.',
            ], 500);
        }

        // Extract transit elements cleanly for analytics tracking
        if (!empty($result['routes']) && is_array($result['routes']) && isset($result['routes'][0])) {
            $routeData = $result['routes'][0];
            $legs = $routeData['legs'] ?? $routeData['segments'] ?? [];

            $transitLegs = array_values(array_filter($legs, fn ($leg) => isset($leg['mode']) && strtoupper($leg['mode']) !== 'WALK'));
            $totalTransitLegs = count($transitLegs);

            if ($totalTransitLegs > 0) {
                $origin = $transitLegs[0]['from']['name'] ?? null;
                $destination = $transitLegs[$totalTransitLegs - 1]['to']['name'] ?? null;
                $summary = $routeData['summary'] ?? '';

                LogJourneyJob::dispatch([
                    'origin_name'      => $origin,
                    'destination_name' => $destination,
                    'primary_route'    => str_starts_with($summary, 'Via ') ? substr($summary, 4) : null,
                    'type'             => 'ai',
                    'user_id'          => auth('sanctum')->user()?->getAuthIdentifier(),
                ])->onQueue('default');
            }
        }

        return response()->json($result);
    }
}
