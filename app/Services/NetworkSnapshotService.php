<?php

namespace App\Services;

use App\Models\NetworkSnapshot;

class NetworkSnapshotService
{
    public static function record(string $type, string $entityId, string $action, array $data): void
    {
        NetworkSnapshot::create([
            'entity_type'   => $type,
            'entity_id'     => $entityId,
            'action'        => $action,
            'snapshot_json' => $data,
            'saved_by'      => auth()->id(),
            'created_at'    => now(),
        ]);
    }
}
