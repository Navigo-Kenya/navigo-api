<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\RouteLicense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RouteLicenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = RouteLicense::with('route:route_id,route_short_name,route_long_name');

        $this->scopeQuery($q, $request);

        if ($request->filled('route_id')) {
            $q->where('route_id', $request->route_id);
        }

        $licenses = $q->orderBy('expiry_date')->get();

        return response()->json($licenses->map(function ($l) {
            return array_merge($l->toArray(), ['status' => $l->status]);
        }));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'          => 'required|string|exists:agencies,agency_id',
            'route_id'           => 'nullable|string|exists:routes,route_id',
            'license_number'     => 'nullable|string|max:100',
            'issuing_authority'  => 'nullable|string|max:100',
            'issue_date'         => 'nullable|date',
            'expiry_date'        => 'nullable|date',
            'goodwill_value'     => 'nullable|numeric|min:0',
            'notes'              => 'nullable|string',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);

        $license = RouteLicense::create($data);

        return response()->json(array_merge($license->toArray(), ['status' => $license->status]), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $license = RouteLicense::findOrFail($id);

        $data = $request->validate([
            'route_id'           => 'nullable|string|exists:routes,route_id',
            'license_number'     => 'nullable|string|max:100',
            'issuing_authority'  => 'nullable|string|max:100',
            'issue_date'         => 'nullable|date',
            'expiry_date'        => 'nullable|date',
            'goodwill_value'     => 'nullable|numeric|min:0',
            'notes'              => 'nullable|string',
        ]);

        $license->update($data);
        $license->refresh();

        return response()->json(array_merge($license->toArray(), ['status' => $license->status]));
    }

    public function destroy(int $id): JsonResponse
    {
        RouteLicense::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}
