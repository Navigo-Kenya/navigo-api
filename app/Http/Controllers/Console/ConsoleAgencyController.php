<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsoleAgencyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Agency::withCount('routes')->orderBy('agency_name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'       => 'required|string|unique:agencies,agency_id',
            'agency_name'     => 'required|string|max:200',
            'agency_url'      => 'required|string|url|max:255',
            'agency_timezone' => 'required|string|max:50',
            'agency_lang'     => 'nullable|string|max:10',
            'agency_phone'    => 'nullable|string|max:50',
            'agency_email'    => 'nullable|email|max:200',
        ]);

        return response()->json(Agency::create($data), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $agency = Agency::findOrFail($id);

        $data = $request->validate([
            'agency_name'     => 'sometimes|string|max:200',
            'agency_url'      => 'sometimes|string|url|max:255',
            'agency_timezone' => 'sometimes|string|max:50',
            'agency_lang'     => 'nullable|string|max:10',
            'agency_phone'    => 'nullable|string|max:50',
            'agency_email'    => 'nullable|email|max:200',
        ]);

        $agency->update($data);

        return response()->json($agency);
    }

    public function destroy(string $id): JsonResponse
    {
        $agency = Agency::withCount('routes')->findOrFail($id);

        if ($agency->routes_count > 0) {
            return response()->json(['message' => 'Cannot delete agency with associated routes.'], 422);
        }

        $agency->delete();

        return response()->json(['message' => 'Agency deleted.']);
    }
}
