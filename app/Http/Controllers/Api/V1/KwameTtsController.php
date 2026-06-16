<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AI\GoogleTtsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KwameTtsController extends Controller
{
    public function __construct(
        protected GoogleTtsService $tts,
    ) {}

    public function speak(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text'                         => ['required', 'string', 'max:1000'],
            'voice_settings'               => ['nullable', 'array'],
            'voice_settings.voice_name'    => ['nullable', 'string', 'max:50'],
            'voice_settings.speaking_rate' => ['nullable', 'numeric', 'between:0.25,4.0'],
            'voice_settings.pitch'         => ['nullable', 'numeric', 'between:-20.0,20.0'],
            'voice_settings.language_code' => ['nullable', 'string', 'max:20'],
        ]);

        $vs = $validated['voice_settings'] ?? [];

        $audio = $this->tts->synthesize(
            $validated['text'],
            (float) ($vs['speaking_rate'] ?? 1.05),
            $vs['voice_name']    ?? 'en-US-Neural2-D',
            (float) ($vs['pitch'] ?? 0.0),
            $vs['language_code'] ?? 'en-US',
        );

        if (!$audio) {
            return response()->json(['error' => 'TTS unavailable'], 422);
        }

        return response()->json(['audio' => $audio]);
    }
}
