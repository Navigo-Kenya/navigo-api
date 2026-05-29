<?php

namespace App\Observers;

use App\Models\Stop;
use App\Services\NetworkSnapshotService;

class StopObserver
{
    public function created(Stop $model): void
    {
        NetworkSnapshotService::record('stop', $model->id, 'created', $model->toArray());
    }

    public function updated(Stop $model): void
    {
        NetworkSnapshotService::record('stop', $model->id, 'updated', $model->toArray());
    }

    public function deleted(Stop $model): void
    {
        NetworkSnapshotService::record('stop', $model->id, 'deleted', $model->toArray());
    }
}
