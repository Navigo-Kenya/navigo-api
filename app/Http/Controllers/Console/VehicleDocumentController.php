<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\VehicleDocument;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VehicleDocumentController extends Controller
{
    public function index(int $vehicleId): JsonResponse
    {
        $docs = VehicleDocument::where('vehicle_id', $vehicleId)
            ->orderBy('document_type')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($docs);
    }

    public function store(Request $request, int $vehicleId): JsonResponse
    {
        Vehicle::findOrFail($vehicleId);

        $data = $request->validate([
            'document_type' => 'required|in:insurance,ntsa_inspection,road_service,speed_limiter,custom',
            'label'         => 'required|string|max:150',
            'file'          => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'expiry_date'   => 'nullable|date',
        ]);

        $file     = $request->file('file');
        $path     = $file->store("vehicle-docs/{$vehicleId}", 'public');
        $fileUrl  = Storage::url($path);

        $doc = VehicleDocument::create([
            'vehicle_id'    => $vehicleId,
            'document_type' => $data['document_type'],
            'label'         => $data['label'],
            'file_url'      => $fileUrl,
            'file_name'     => $file->getClientOriginalName(),
            'mime_type'     => $file->getMimeType(),
            'file_size'     => $file->getSize(),
            'expiry_date'   => $data['expiry_date'] ?? null,
            'uploaded_by'   => $request->user()?->id,
        ]);

        return response()->json($doc, 201);
    }

    public function update(Request $request, int $vehicleId, int $id): JsonResponse
    {
        $doc = VehicleDocument::where('vehicle_id', $vehicleId)->findOrFail($id);

        $data = $request->validate([
            'label'       => 'sometimes|string|max:150',
            'expiry_date' => 'nullable|date',
        ]);

        $doc->update($data);

        return response()->json($doc);
    }

    public function destroy(int $vehicleId, int $id): JsonResponse
    {
        $doc = VehicleDocument::where('vehicle_id', $vehicleId)->findOrFail($id);

        // Delete from storage
        $path = str_replace('/storage/', '', $doc->file_url);
        Storage::disk('public')->delete($path);

        $doc->delete();

        return response()->json(null, 204);
    }
}
