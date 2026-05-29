<?php

namespace App\Observers;

use App\Models\Trip;
use App\Services\NetworkSnapshotService;

class TripObserver
{
    public function created(Trip $model): void
    {
        NetworkSnapshotService::record('trip', $model->trip_id, 'created', $model->toArray());
    }

    public function updated(Trip $model): void
    {
        NetworkSnapshotService::record('trip', $model->trip_id, 'updated', $model->toArray());
    }

    public function deleted(Trip $model): void
    {
        NetworkSnapshotService::record('trip', $model->trip_id, 'deleted', $model->toArray());
    }
}
