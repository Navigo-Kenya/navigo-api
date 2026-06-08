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
     */
    public function store(StoreTransitReportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->id; // Tag the report to the user

        // Create the record via the service we built earlier
        $report = $this->reportService->createReport($data);

        // Attach the lat/lng coordinates to the model instance for the Event broadcaster
        $report->lat = $data['lat'];
        $report->lng = $data['lng'];

        // Fire the WebSocket event instantly
        event(new TransitReportCreated($report));

        return response()->json([
            'message' => 'Report broadcasted successfully',
            'data'    => [
                'id' => $report->id,
            ]
        ], 201);
    }
}