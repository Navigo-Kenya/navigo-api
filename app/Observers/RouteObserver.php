<?php

namespace App\Observers;

use App\Models\Route;
use App\Services\NetworkSnapshotService;

class RouteObserver
{
    public function created(Route $model): void
    {
        NetworkSnapshotService::record('route', $model->route_id, 'created', $model->toArray());
    }

    public function updated(Route $model): void
    {
        NetworkSnapshotService::record('route', $model->route_id, 'updated', $model->toArray());
    }

    public function deleted(Route $model): void
    {
        NetworkSnapshotService::record('route', $model->route_id, 'deleted', $model->toArray());
    }
}
