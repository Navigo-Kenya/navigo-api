<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\LogJourneyJob;
use App\Services\AiAssistantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AiTransitController extends Controller
{
    public function __construct(protected AiAssistantService $aiService) {}

    public function planRouteWithAi(Request $request)
    {
        // Full pipeline can take up to 2 min (Whisper + 2× GPT + OTP + TTS)
        set_time_limit(180);

        $result = $this->aiService->chat(
            sessionId: $request->input('session_id', 'default'),
            text:      $request->input('text'),
            audioFile: $request->input('audio'),
            userLat:   $request->input('lat'),
            userLng:   $request->input('lng'),
            aliases:   $request->input('aliases', []),
        );

        if (!$result) {
            return response()->json(['error' => 'Could not process your request.'], 400);
        }

        Log::info('Kwame chat turn completed', [
            'session'   => $request->input('session_id'),
            'has_route' => !empty($result['route']),
            'has_hold'  => !empty($result['holding_phrase']),
        ]);

        if (!empty($result['route']) && \is_array($result['route'])) {
            $first   = $result['route'][0];
            $transit = array_values(array_filter($first['segments'] ?? [], fn ($s) => $s['mode'] !== 'WALK'));

            $origin      = $transit[0]['from']['name'] ?? null;
            $destination = \count($transit) > 0 ? array_reverse($transit)[0]['to']['name'] : null;
            $summary     = $first['summary'] ?? '';

            LogJourneyJob::dispatch([
                'origin_name'      => $origin,
                'destination_name' => $destination,
                'primary_route'    => str_starts_with($summary, 'Via ') ? substr($summary, 4) : null,
                'type'             => 'ai',
                'user_id'          => auth()->id(),
            ])->onQueue('default');
        }

        return response()->json($result);
    }
}
