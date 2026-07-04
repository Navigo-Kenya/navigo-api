<?php

namespace App\Jobs;

use App\Models\JourneyLog;
use App\Models\SavedPlace;
use App\Models\User;
use App\Services\Kwame\KwameToolsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Weekday-morning commute briefing (runs 06:30 Nairobi via the scheduler).
 *
 * Eligible users: at least one device token, journey_reminder notifications
 * enabled (that's the toggle the briefing rides on), and either a home/work
 * pin or a frequent destination in the last 30 days. Composition is
 * template-first; weather comes from the Kwame weather tool (cached 10 min,
 * so shared home areas don't hammer the API).
 */
class SendMorningBriefingJob implements ShouldQueue
{
    use Queueable;

    public function handle(KwameToolsService $tools): void
    {
        $users = User::whereHas('deviceTokens')
            ->whereNull('banned_at')
            ->get(['id', 'name', 'settings']);

        $sent = 0;
        foreach ($users as $user) {
            $prefs = $user->settings['notifications'] ?? [];
            if (($prefs['master'] ?? true) === false) continue;
            if (($prefs['journey_reminder'] ?? true) === false) continue;

            $briefing = $this->composeFor($user, $tools);
            if (!$briefing) continue;

            SendPushNotificationJob::dispatch(
                $user->id,
                'journey_reminder',
                $briefing['title'],
                $briefing['body'],
                ['screen' => '/(tabs)/home', 'type' => 'morning_briefing'],
            )->onQueue('default');
            $sent++;
        }

        Log::info("Morning briefing dispatched to {$sent} users.");
    }

    /** @return array{title: string, body: string}|null */
    private function composeFor(User $user, KwameToolsService $tools): ?array
    {
        // Anchor location: home pin, else work pin.
        $pins = SavedPlace::where('user_id', $user->id)
            ->whereIn('pin', ['home', 'work'])
            ->get(['name', 'lat', 'lng', 'pin'])
            ->keyBy('pin');
        $anchor = $pins->get('home') ?? $pins->get('work');

        // Frequent destination over the last 30 days.
        $frequent = JourneyLog::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('destination_name')
            ->selectRaw('destination_name, COUNT(*) as trips')
            ->groupBy('destination_name')
            ->orderByDesc('trips')
            ->first();

        // Not enough signal to write anything useful — skip silently.
        if (!$anchor && (!$frequent || $frequent->trips < 3)) return null;

        $parts = [];

        // Weather line (from the anchor's coordinates; Nairobi fallback inside the tool).
        $weather = $tools->getWeather(
            $anchor ? (float) $anchor->lat : null,
            $anchor ? (float) $anchor->lng : null,
        );
        if (empty($weather['error'])) {
            $condition = $weather['condition'] ?? null;
            $temp      = $weather['temperature_c'] ?? null;
            $rain      = $weather['rain_chance_pct'] ?? null;
            if ($condition && $temp !== null) {
                $line = "{$condition}, " . round((float) $temp) . "°C";
                if ($rain !== null && (int) $rain >= 40) {
                    $line .= " — {$rain}% chance of rain, carry an umbrella";
                }
                $parts[] = $line;
            }
        }

        if ($frequent && $frequent->trips >= 3) {
            $parts[] = "Planning your usual trip to {$frequent->destination_name}? Check routes before you leave";
        } elseif ($anchor) {
            $parts[] = 'Check this morning\'s routes before you head out';
        }

        if (empty($parts)) return null;

        $firstName = explode(' ', trim($user->name))[0] ?: 'there';

        return [
            'title' => "Good morning, {$firstName} ☀️",
            'body'  => implode('. ', $parts) . '.',
        ];
    }
}
