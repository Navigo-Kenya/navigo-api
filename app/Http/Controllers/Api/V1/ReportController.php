<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\TransitReportCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransitReportRequest;
use App\Http\Requests\ViewportRequest;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(private ReportService $reportService) {}

    /**
     * Fetch active reports for the current map viewport.
     */
    public function viewport(ViewportRequest $request): JsonResponse
    {
        $reports = $this->reportService->getReportsInViewport(
            $request->validated('north'),
            $request->validated('south'),
            $request->validated('east'),
            $request->validated('west')
        );

        return response()->json(['data' => $reports]);
    }

    /**
     * Store a new crowdsourced report and broadcast it.
     * Route is public — user_id is nullable so guests can also report.
     */
    public function store(StoreTransitReportRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['user_id'] = $request->user()?->id; // null-safe: route is public

            $report = $this->reportService->createReport($data);

            // Attach coordinates for the WebSocket broadcaster (not persisted here)
            $report->lat = $data['lat'];
            $report->lng = $data['lng'];

            event(new TransitReportCreated($report));

            return response()->json([
                'message' => 'Report broadcasted successfully',
                'data'    => ['id' => $report->id],
            ], 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to submit report.'], 500);
        }
    }
}
