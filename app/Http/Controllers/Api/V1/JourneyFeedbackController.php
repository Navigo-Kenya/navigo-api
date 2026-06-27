<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JourneyFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JourneyFeedbackController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status'         => 'required|in:submitted,dismissed',
            'rating'         => 'nullable|integer|min:1|max:5',
            'fare_choice'    => 'nullable|in:matched,more,less',
            'custom_fare'    => 'nullable|integer|min:1',
            'estimated_fare' => 'nullable|integer|min:0',
            'currency'       => 'nullable|string|max:10',
            'tags'           => 'nullable|array',
            'tags.*'         => 'string|max:50',
            'to_name'        => 'nullable|string|max:255',
            'route_summary'  => 'nullable|string|max:255',
            'guest_token'    => 'nullable|string|max:64',
        ]);

        // Works for both authenticated users (Bearer token sent by apiClient)
        // and guests (user_id stays null, guest_token identifies the device).
        $feedback = JourneyFeedback::create([
            ...$validated,
            'user_id'    => auth('sanctum')->id(),
            'currency'   => $validated['currency'] ?? 'KES',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['id' => $feedback->id], 201);
    }
}
