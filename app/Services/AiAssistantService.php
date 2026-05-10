<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class AiAssistantService
{
    protected string $model = 'gemini-2.5-flash';

    /**
     * Extracts transit parameters from either a Text string or a Base64 Audio file.
     */
    public function extractTransitIntent(?string $text = null, ?array $audioFile = null): ?array
    {
        $apiKey = env('GEMINI_API_KEY');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$apiKey}";

        // The Master Prompt: Forcing the LLM into a rigid data-extraction mode + Personality
        $systemPrompt = "You are Kwame, a friendly and extremely helpful transit assistant for Nairobi's Matatu network.
        Your job is to extract routing parameters AND provide a short, conversational response.
        Do NOT output markdown. Return ONLY valid JSON.

        JSON Schema:
        {
            \"from\": \"Origin location (string or 'Current Location' if unspecified)\",
            \"to\": \"Destination location (string)\",
            \"walkReluctance\": \"Float. Default is 13.5. If they mention heavy bags, rain, or hating walking, increase up to 25.0.\",
            \"spoken_response\": \"A short, friendly, 1-to-2 sentence response. e.g. 'Sawa! I found a great route to Aviation. I adjusted it to minimize walking since you have heavy bags. Let's go!'\"
        }";

        $tools = [
            [
                'function_declarations' => [
                    [
                        'name' => 'get_route_info',
                        'description' => 'Get information about a specific Matatu line/route in Nairobi, including its stops and operating status.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'route_name' => [
                                    'type' => 'STRING',
                                    'description' => 'The alphanumeric name of the Matatu line, e.g., 33C, 46, 11F'
                                ]
                            ],
                            'required' => ['route_name']
                        ]
                    ]
                ]
            ]
        ];

        // Build the request parts based on whether we have text, audio, or both
        $parts = [];
        if ($text) {
            $parts[] = ['text' => $text];
        }
        if ($audioFile) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $audioFile['mime'], // e.g., 'audio/mp4'
                    'data' => $audioFile['base64']
                ]
            ];
        }

        try {
            $response = Http::withoutVerifying()->timeout(10)->post($url, [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]]
                ],
                'contents' => [
                    ['parts' => $parts]
                ],
                'generationConfig' => [
                    'response_mime_type' => 'application/json', // CRITICAL: Forces Gemini to output pure JSON
                    'temperature' => 0.1, // Low temperature for highly deterministic, non-creative output
                ],
                // 'tools' => $tools
            ]);

            if (!$response->successful()) {
                Log::error("Gemini API failed", ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            // Extract the JSON string from Gemini's response
            $jsonString = $response->json('candidates.0.content.parts.0.text');

            return json_decode($jsonString, true);

        } catch (Exception $e) {
            Log::error("AI Extraction Error: " . $e->getMessage());
            return null;
        }
    }
}
