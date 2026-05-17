<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\AiAssistantService;
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

        return response()->json($result);
    }
}
