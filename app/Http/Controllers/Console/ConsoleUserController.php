<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Jobs\SendPushNotificationJob;
use App\Models\Badge;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConsoleUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = User::withCount(['contributions']);

        if ($search = $request->input('search')) {
            $q->where(function ($query) use ($search) {
                $query->where('name', 'ilike', "%{$search}%")
                      ->orWhere('email', 'ilike', "%{$search}%")
                      ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        if ($role = $request->input('role')) {
            $q->where('role', $role);
        }

        if ($request->input('banned') === 'true') {
            $q->whereNotNull('banned_at');
        } elseif ($request->input('banned') === 'false') {
            $q->whereNull('banned_at');
        }

        $users = $q->latest()->paginate((int) $request->input('per_page', 25));

        return response()->json($users);
    }

    public function show(int $id): JsonResponse
    {
        $user = User::withCount(['contributions', 'savedPlaces', 'savedJourneys'])
            ->with(['badges', 'contributions' => fn ($q) => $q->latest()->limit(10)])
            ->findOrFail($id);

        return response()->json($user);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'role' => ['sometimes', Rule::in(['user', 'moderator', 'admin', 'superadmin'])],
        ]);

        // Prevent demotion of own superadmin account
        if (isset($data['role']) && $user->id === $request->user()->id) {
            return response()->json(['message' => 'Cannot change your own role.'], 422);
        }

        $user->update($data);

        return response()->json($user);
    }

    public function ban(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $user = User::findOrFail($id);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Cannot ban yourself.'], 422);
        }

        $user->update([
            'banned_at'  => now(),
            'ban_reason' => $data['reason'],
        ]);

        // Revoke all tokens
        $user->tokens()->delete();

        return response()->json(['message' => 'User banned.']);
    }

    public function unban(int $id): JsonResponse
    {
        User::findOrFail($id)->update(['banned_at' => null, 'ban_reason' => null]);
        return response()->json(['message' => 'User unbanned.']);
    }

    public function adjustPoints(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'points' => 'required|integer',
            'reason' => 'required|string|max:255',
        ]);

        $user = User::findOrFail($id);
        $user->increment('points', $data['points']);

        return response()->json(['points' => $user->fresh()->points]);
    }

    public function awardBadge(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['badge_id' => 'required|exists:badges,id']);

        $already = UserBadge::where('user_id', $id)->where('badge_id', $data['badge_id'])->exists();
        if ($already) {
            return response()->json(['message' => 'User already has this badge.'], 422);
        }

        UserBadge::create(['user_id' => $id, 'badge_id' => $data['badge_id'], 'earned_at' => now()]);

        return response()->json(['message' => 'Badge awarded.']);
    }

    public function revokeBadge(int $userId, int $badgeId): JsonResponse
    {
        UserBadge::where('user_id', $userId)->where('badge_id', $badgeId)->delete();
        return response()->json(['message' => 'Badge revoked.']);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('superadmin', $request->user());

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Name', 'Email', 'Phone', 'Role', 'Points', 'Level', 'Contributions', 'Joined', 'Banned']);

            User::withCount('contributions')->chunkById(200, function ($users) use ($handle) {
                foreach ($users as $u) {
                    fputcsv($handle, [
                        $u->id, $u->name, $u->email, $u->phone_number,
                        $u->role, $u->points, $u->level(), $u->contributions_count,
                        $u->created_at->toDateString(), $u->banned_at?->toDateString() ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, 'hopln-users-' . now()->format('Y-m-d') . '.csv');
    }
}
