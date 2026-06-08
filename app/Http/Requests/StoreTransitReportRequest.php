<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransitReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Assuming 'auth:sanctum' middleware is on the route
    }

    public function rules(): array
    {
        return [
            'lat'  => ['required', 'numeric', 'between:-90,90'],
            'lng'  => ['required', 'numeric', 'between:-180,180'],
            'type' => ['required', 'string', 'in:stage_queue,accident,police_check,flooded_route,fare_hike'],
        ];
    }
}