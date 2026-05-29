<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(30);

        return response()->json($notifications);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_if($notification->user_id !== $request->user()->id, 403);
        $notification->markRead();
        return response()->json(['status' => 'ok']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['status' => 'ok']);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $request->user()->notifications()->whereNull('read_at')->count();
        return response()->json(['count' => $count]);
    }
}
