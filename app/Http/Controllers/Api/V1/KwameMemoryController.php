<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserMemory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Transparency endpoints for Kwame's persistent memory:
 * users can see and delete everything the assistant remembers about them.
 */
class KwameMemoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $memories = UserMemory::where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->get(['id', 'kind', 'content', 'source', 'created_at']);

        return response()->json(['data' => $memories]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        UserMemory::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail()
            ->delete();

        return response()->json(null, 204);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        UserMemory::where('user_id', $request->user()->id)->delete();

        return response()->json(null, 204);
    }
}
