<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AI\GoogleSttService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KwameSttController extends Controller
{
    public function __construct(
        protected GoogleSttService $stt,
    ) {}

    public function transcribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'audio_base64' => ['required', 'string'],
        ]);

        $transcript = $this->stt->transcribe($validated['audio_base64']);

        return response()->json([
            'transcript' => $transcript
        ]);
    }
}