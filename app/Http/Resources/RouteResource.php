<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isTransfer = $this->type === 'transfer';

        // Add the summary generator here so the frontend receives it!
        $summary = $isTransfer 
            ? "Take {$this->leg1_route_name} then change to {$this->leg2_route_name}" 
            : "Direct via {$this->route_short_name}";

        return [
            'type' => $this->type,
            'summary' => $summary, // Send it to React Native
            'segments' => $isTransfer ? $this->formatTransfer() : $this->formatDirect(),
        ];
    }

    private function formatDirect(): array
    {
        return [[
            'route_id' => $this->route_id,
            'route_name' => $this->route_short_name,
            'trip_id' => $this->trip_id,
            'points' => $this->decodeShape($this->shape_geojson),
            'board_stop' => $this->board_stop ? new StopResource($this->board_stop) : null,
            'alight_stop' => $this->alight_stop ? new StopResource($this->alight_stop) : null,
        ]];
    }

    private function formatTransfer(): array
    {
        return [
            [
                'route_id' => $this->leg1_route_id,
                'route_name' => $this->leg1_route_name,
                'trip_id' => $this->leg1_trip_id,
                'points' => $this->decodeShape($this->leg1_shape_geojson),
                'board_stop' => $this->board_stop ? new StopResource($this->board_stop) : null,
                'alight_stop' => $this->transfer_stop ? new StopResource($this->transfer_stop) : null,
            ],
            [
                'route_id' => $this->leg2_route_id,
                'route_name' => $this->leg2_route_name,
                'trip_id' => $this->leg2_trip_id,
                'points' => $this->decodeShape($this->leg2_shape_geojson),
                'board_stop' => $this->transfer_stop ? new StopResource($this->transfer_stop) : null,
                'alight_stop' => $this->alight_stop ? new StopResource($this->alight_stop) : null,
            ]
        ];
    }

    private function decodeShape(?string $geojson): array
    {
        if (!$geojson) return [];
        $data = json_decode($geojson, true);
        return array_map(fn($pt) => [(float)$pt[0], (float)$pt[1]], $data['coordinates'] ?? []);
    }
}