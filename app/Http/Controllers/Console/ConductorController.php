<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Conductor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConductorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Conductor::query();

        $this->scopeQuery($q, $request);

        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $q->where(function ($sq) use ($request) {
                $sq->where('name', 'ilike', '%'.$request->search.'%')
                   ->orWhere('phone', 'ilike', '%'.$request->search.'%')
                   ->orWhere('psv_badge_no', 'ilike', '%'.$request->search.'%');
            });
        }

        return response()->json($q->orderBy('name')->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'        => 'required|string|exists:agencies,agency_id',
            'name'             => 'required|string|max:255',
            'phone'            => 'nullable|string|max:30',
            'psv_badge_no'     => 'nullable|string|max:50',
            'psv_badge_expiry' => 'nullable|date',
            'status'           => 'in:active,suspended,terminated',
            'notes'            => 'nullable|string',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);

        $conductor = Conductor::create($data);
        return response()->json($conductor, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $conductor = Conductor::findOrFail($id);
        $this->assertAgencyAllowed($request, $conductor->agency_id);

        $data = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'phone'            => 'nullable|string|max:30',
            'psv_badge_no'     => 'nullable|string|max:50',
            'psv_badge_expiry' => 'nullable|date',
            'status'           => 'in:active,suspended,terminated',
            'notes'            => 'nullable|string',
        ]);

        $conductor->update($data);
        return response()->json($conductor);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $conductor = Conductor::findOrFail($id);
        $this->assertAgencyAllowed($request, $conductor->agency_id);
        $conductor->delete();
        return response()->json(null, 204);
    }
}
