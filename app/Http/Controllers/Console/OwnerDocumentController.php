<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\OwnerDocument;
use App\Models\VehicleOwner;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnerDocumentController extends Controller
{
    public function __construct(private StorageService $storage) {}

    public function index(Request $request, VehicleOwner $owner): JsonResponse
    {
        $this->assertAgencyAllowed($request, $owner->agency_id);

        return response()->json($owner->documents()->orderByDesc('created_at')->get());
    }

    public function store(Request $request, VehicleOwner $owner): JsonResponse
    {
        $this->assertAgencyAllowed($request, $owner->agency_id);

        $data = $request->validate([
            'document_type' => 'required|string|in:national_id,contract,other',
            'label'         => 'required|string|max:255',
            'expiry_date'   => 'nullable|date',
            'file'          => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $url = $this->storage->upload($request->file('file'), "owner-documents/{$owner->id}");

        $doc = $owner->documents()->create([
            'document_type' => $data['document_type'],
            'label'         => $data['label'],
            'expiry_date'   => $data['expiry_date'] ?? null,
            'file_url'      => $url,
        ]);

        return response()->json($doc, 201);
    }

    public function destroy(Request $request, VehicleOwner $owner, OwnerDocument $document): JsonResponse
    {
        $this->assertAgencyAllowed($request, $owner->agency_id);

        if ($document->owner_id !== $owner->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $this->storage->delete($document->file_url);

        $document->delete();

        return response()->json(null, 204);
    }
}
