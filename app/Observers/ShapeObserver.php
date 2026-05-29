<?php

namespace App\Observers;

use App\Models\Shape;
use App\Services\NetworkSnapshotService;

class ShapeObserver
{
    public function created(Shape $model): void
    {
        NetworkSnapshotService::record('shape', $model->shape_id, 'created', $model->toArray());
    }

    public function updated(Shape $model): void
    {
        NetworkSnapshotService::record('shape', $model->shape_id, 'updated', $model->toArray());
    }

    public function deleted(Shape $model): void
    {
        NetworkSnapshotService::record('shape', $model->shape_id, 'deleted', $model->toArray());
    }
}
