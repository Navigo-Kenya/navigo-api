<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Jobs\OtpSyncJob;
use App\Models\OtpLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class OtpController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json([
            'last_sync'     => Cache::get('otp:last_sync'),
            'last_duration' => Cache::get('otp:last_duration'),
            'status'        => Cache::get('otp:sync_status', 'unknown'),
            'error'         => Cache::get('otp:sync_error'),
        ]);
    }

    public function log(Request $request): JsonResponse
    {
        $query = OtpLog::orderByDesc('created_at');

        if ($request->filled('event')) {
            $query->where('event', $request->input('event'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function cancel(): JsonResponse
    {
        Cache::put('otp:cancel_requested', true, now()->addMinutes(5));

        return response()->json(['message' => 'Cancel requested.']);
    }

    public function sync(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Superadmin only.'], 403);
        }

        if (Cache::get('otp:sync_status') === 'running') {
            return response()->json(['message' => 'Sync already in progress.'], 409);
        }

        OtpSyncJob::dispatch()->onQueue('otp');

        return response()->json(['message' => 'OTP sync queued.']);
    }
}
