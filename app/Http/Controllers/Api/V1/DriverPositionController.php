<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\VehiclePosition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverPositionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_id' => 'required|integer|exists:vehicles,id',
            'trip_id'    => 'nullable|string|exists:trips,trip_id',
            'lat'        => 'required|numeric|between:-90,90',
            'lng'        => 'required|numeric|between:-180,180',
            'bearing'    => 'nullable|integer|between:0,359',
            'speed_kmh'  => 'nullable|numeric|min:0',
        ]);

        $data['created_at'] = now();

        VehiclePosition::create($data);

        return response()->json(['status' => 'ok'], 201);
    }
}
