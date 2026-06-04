<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceAlert;
use Illuminate\Http\JsonResponse;

class AlertsController extends Controller
{
    public function index(): JsonResponse
    {
        $alerts = ServiceAlert::where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'title', 'description', 'severity', 'effect', 'affected_type', 'affected_id', 'starts_at', 'ends_at']);

        return response()->json(['data' => $alerts]);
    }
}
