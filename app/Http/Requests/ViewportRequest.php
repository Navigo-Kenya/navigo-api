<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ViewportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Anyone can view the map
    }

    public function rules(): array
    {
        return [
            'north' => ['required', 'numeric', 'between:-90,90'],
            'south' => ['required', 'numeric', 'between:-90,90'],
            'east'  => ['required', 'numeric', 'between:-180,180'],
            'west'  => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}