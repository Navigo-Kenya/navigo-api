<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Badge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BadgeController extends Controller
{
    public function index(): JsonResponse
    {
        $badges = Badge::withCount('users')->orderBy('name')->get();
        return response()->json($badges);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100|unique:badges,name',
            'description' => 'required|string|max:500',
            'icon'        => 'required|string|max:100',
            'criteria'    => 'nullable|string|max:500',
            'points_threshold' => 'nullable|integer|min:0',
        ]);

        $badge = Badge::create($data);
        return response()->json($badge, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $badge = Badge::findOrFail($id);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:100',
            'description' => 'sometimes|string|max:500',
            'icon'        => 'sometimes|string|max:100',
            'criteria'    => 'nullable|string|max:500',
            'points_threshold' => 'nullable|integer|min:0',
        ]);

        $badge->update($data);
        return response()->json($badge);
    }

    public function destroy(int $id): JsonResponse
    {
        Badge::findOrFail($id)->delete();
        return response()->json(['message' => 'Badge deleted.']);
    }
}
