<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\FleetDevice;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FleetDeviceController extends Controller
{
    public function index(int $vehicleId): JsonResponse
    {
        $devices = FleetDevice::where('vehicle_id', $vehicleId)
            ->orderBy('device_type')
            ->get();

        return response()->json($devices);
    }

    public function store(Request $request, int $vehicleId): JsonResponse
    {
        Vehicle::findOrFail($vehicleId);

        $data = $request->validate([
            'device_type'  => 'required|in:gps_tracker,fuel_sensor,dash_cam,panic_button,eld,custom',
            'brand'        => 'nullable|string|max:100',
            'model'        => 'nullable|string|max:100',
            'imei'         => 'nullable|string|max:20',
            'protocol'     => 'nullable|string|max:50',
            'server_ip'    => 'nullable|ip',
            'server_port'  => 'nullable|integer|min:1|max:65535',
            'is_active'    => 'boolean',
            'meta'         => 'nullable|array',
            'notes'        => 'nullable|string',
        ]);

        $data['vehicle_id'] = $vehicleId;
        $data['added_by']   = $request->user()?->id;

        $device = FleetDevice::create($data);

        return response()->json($device, 201);
    }

    public function update(Request $request, int $vehicleId, int $id): JsonResponse
    {
        $device = FleetDevice::where('vehicle_id', $vehicleId)->findOrFail($id);

        $data = $request->validate([
            'device_type'  => 'sometimes|in:gps_tracker,fuel_sensor,dash_cam,panic_button,eld,custom',
            'brand'        => 'nullable|string|max:100',
            'model'        => 'nullable|string|max:100',
            'imei'         => 'nullable|string|max:20',
            'protocol'     => 'nullable|string|max:50',
            'server_ip'    => 'nullable|ip',
            'server_port'  => 'nullable|integer|min:1|max:65535',
            'is_active'    => 'boolean',
            'meta'         => 'nullable|array',
            'notes'        => 'nullable|string',
        ]);

        $device->update($data);

        return response()->json($device);
    }

    public function destroy(int $vehicleId, int $id): JsonResponse
    {
        FleetDevice::where('vehicle_id', $vehicleId)->findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    public function rotateToken(int $vehicleId, int $id): JsonResponse
    {
        $device = FleetDevice::where('vehicle_id', $vehicleId)->findOrFail($id);
        $token  = $device->rotateToken();

        return response()->json(['ingest_token' => $token]);
    }
}
