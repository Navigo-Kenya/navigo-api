<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\TransitReportCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransitReportRequest;
use App\Http\Requests\ViewportRequest;
use App\Models\TransitReport;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            $data        = $request->validated();
            $authUser    = $request->user();
            $data['user_id']      = $authUser?->id;
            $data['is_anonymous'] = $authUser
                ? ($authUser->settings['privacy']['anonymous_reports'] ?? false)
                : false;

            $result = $this->reportService->createReport($data);
            $report = $result['report'];

            $report->lat = $data['lat'];
            $report->lng = $data['lng'];

            event(new TransitReportCreated($report));

            return response()->json([
                'message' => 'Report broadcasted successfully',
                'data'    => [
                    'id'             => $report->id,
                    'points_awarded' => $result['points_awarded'],
                    'new_badges'     => $result['new_badges'],
                ],
            ], 201);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'duplicate') {
                return response()->json(['message' => 'A similar report already exists nearby.'], 409);
            }
            return response()->json(['message' => 'Failed to submit report.'], 500);
        } catch (\Throwable) {
            return response()->json(['message' => 'Failed to submit report.'], 500);
        }
    }

    /**
     * Cast or toggle a vote (up/down) on a report.
     * Public route — authenticated users are identified by session,
     * guests by a SHA-256 hash of their IP address.
     */
    public function vote(Request $request, string $id): JsonResponse
    {
        $request->validate(['vote' => 'required|in:up,down']);

        $report = TransitReport::where('id', $id)->where('status', 'active')->first();
        if (!$report) {
            return response()->json(['message' => 'Report not found or no longer active.'], 404);
        }

        try {
            $ipHash = hash('sha256', $request->ip() ?? '');
            $counts = $this->reportService->voteReport(
                $report,
                $request->string('vote')->value(),
                $request->user()?->id,
                $ipHash
            );

            return response()->json(['data' => $counts]);
        } catch (\Throwable) {
            return response()->json(['message' => 'Could not record your vote.'], 500);
        }
    }
}
