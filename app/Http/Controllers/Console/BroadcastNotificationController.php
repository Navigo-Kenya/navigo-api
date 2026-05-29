<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Jobs\SendPushNotificationJob;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BroadcastNotificationController extends Controller
{
    public function index(): JsonResponse
    {
        $broadcasts = DB::table('broadcast_notifications')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($broadcasts);
    }

    public function broadcast(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'    => 'required|string|max:100',
            'body'     => 'required|string|max:500',
            'type'     => 'required|in:info,tips,update,alert',
            'audience' => 'required|in:all,active_30d,inactive_30d',
        ]);

        $query = User::whereNotNull('id');

        if ($data['audience'] === 'active_30d') {
            $query->where('updated_at', '>=', now()->subDays(30));
        } elseif ($data['audience'] === 'inactive_30d') {
            $query->where('updated_at', '<', now()->subDays(30));
        }

        $userIds = $query->pluck('id');
        $count   = $userIds->count();

        // Record the broadcast
        $broadcastId = DB::table('broadcast_notifications')->insertGetId([
            'title'      => $data['title'],
            'body'       => $data['body'],
            'type'       => $data['type'],
            'audience'   => $data['audience'],
            'sent_to'    => $count,
            'created_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Dispatch per-user push jobs
        foreach ($userIds as $userId) {
            SendPushNotificationJob::dispatch($userId, $data['type'], $data['title'], $data['body'])
                ->onQueue('notifications');
        }

        return response()->json([
            'message'      => "Broadcast queued for {$count} users.",
            'broadcast_id' => $broadcastId,
            'sent_to'      => $count,
        ]);
    }
}
