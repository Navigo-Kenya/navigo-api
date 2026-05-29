<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'       => 'required|string',
            'platform'    => 'required|in:ios,android,web',
            'device_name' => 'nullable|string|max:100',
        ]);

        $request->user()->deviceTokens()->updateOrCreate(
            ['token' => $data['token']],
            $data,
        );

        return response()->json(['status' => 'ok']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['token' => 'required|string']);

        $request->user()->deviceTokens()
            ->where('token', $request->token)
            ->delete();

        return response()->json(['status' => 'ok']);
    }
}
