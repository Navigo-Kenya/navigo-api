<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CoverageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class CoverageController extends Controller
{
    public function __construct(private readonly CoverageService $service) {}

    public function index(): JsonResponse
    {
        try {
            $data = Cache::remember('coverage:v3', 900, fn () => $this->service->getCoverageData());
        } catch (\Throwable) {
            // Cache backend unavailable, compute directly without caching
            $data = $this->service->getCoverageData();
        }

        return response()->json($data)
            ->header('Cache-Control', 'public, max-age=900, stale-while-revalidate=300');
    }
}
