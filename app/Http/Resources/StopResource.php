<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'lat' => $this->lat, // This uses the Accessor we wrote in the Stop model!
            'lng' => $this->lng,
            'location_t' => $this->location_t,
            'parent_sta' => $this->parent_sta,
            'trip_count' => $this->trip_count,
            'trip_ids' => $this->trip_ids,
            'route_ids' => $this->route_ids,
            'route_nams' => $this->route_nams,

            // This will only be included if we are running a spatial "distance" query
            $this->mergeWhen(isset($this->distance), [
                'dist' => round($this->distance, 1),
            ]),
        ];
    }
}
