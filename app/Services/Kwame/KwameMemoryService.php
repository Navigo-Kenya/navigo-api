<?php

namespace App\Services\Kwame;

use App\Models\JourneyLog;
use App\Models\SavedPlace;
use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Support\Facades\Log;

/**
 * Durable per-user memory for Kwame: saved-place pins, frequent destinations
 * derived from journey logs, and preferences the user has stated in chat
 * (captured via the remember_preference tool).
 *
 * buildMemoryBlock() renders the compact prompt section; keep it small —
 * it rides inside every LLM request for authenticated users.
 */
class KwameMemoryService
{
    private const MAX_STORED_MEMORIES = 20;
    private const PROMPT_MEMORY_CAP   = 10;

    /** Prompt block ("WHAT YOU KNOW ABOUT THIS USER") or null when empty. */
    public function buildMemoryBlock(User $user): ?string
    {
        $lines = [];

        // Saved pins — the highest-signal facts.
        $pins = SavedPlace::where('user_id', $user->id)
            ->whereIn('pin', ['home', 'work'])
            ->get(['name', 'pin']);
        foreach ($pins as $pin) {
            $lines[] = "- {$pin->pin} is \"{$pin->name}\" (resolve \"home\"/\"work\" to it directly)";
        }

        // Frequent destinations from journey history (last 60 days).
        try {
            $frequent = JourneyLog::where('user_id', $user->id)
                ->where('created_at', '>=', now()->subDays(60))
                ->whereNotNull('destination_name')
                ->selectRaw('destination_name, COUNT(*) as trips')
                ->groupBy('destination_name')
                ->orderByDesc('trips')
                ->limit(3)
                ->get();
            foreach ($frequent as $row) {
                if ($row->trips >= 2) {
                    $lines[] = "- travels often to {$row->destination_name} ({$row->trips} recent trips)";
                }
            }
        } catch (\Throwable $e) {
            Log::warning('KwameMemory: frequent-destination query failed: ' . $e->getMessage());
        }

        // Stated preferences / durable facts.
        try {
            $memories = UserMemory::where('user_id', $user->id)
                ->orderByDesc('last_used_at')
                ->orderByDesc('updated_at')
                ->limit(self::PROMPT_MEMORY_CAP)
                ->get();
            foreach ($memories as $m) {
                $lines[] = '- ' . $m->content;
            }
            if ($memories->isNotEmpty()) {
                UserMemory::whereIn('id', $memories->pluck('id'))->update(['last_used_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::warning('KwameMemory: user_memories query failed: ' . $e->getMessage());
        }

        if (empty($lines)) return null;

        return "WHAT YOU KNOW ABOUT THIS USER (persistent memory — apply silently, don't recite):\n"
            . implode("\n", $lines);
    }

    /**
     * Store a durable preference the user just stated. Returns true when
     * saved, false when deduped or capped.
     */
    public function rememberPreference(User $user, string $content): bool
    {
        $content = trim(mb_substr($content, 0, 200));
        if ($content === '') return false;

        // Cheap dedupe: identical normalized content.
        $exists = UserMemory::where('user_id', $user->id)
            ->whereRaw('LOWER(content) = ?', [mb_strtolower($content)])
            ->exists();
        if ($exists) return false;

        // Cap: evict the least-recently-used memory beyond the limit.
        $count = UserMemory::where('user_id', $user->id)->count();
        if ($count >= self::MAX_STORED_MEMORIES) {
            UserMemory::where('user_id', $user->id)
                ->orderBy('last_used_at')
                ->orderBy('updated_at')
                ->first()
                ?->delete();
        }

        UserMemory::create([
            'user_id'      => $user->id,
            'kind'         => 'preference',
            'content'      => $content,
            'source'       => 'stated',
            'last_used_at' => now(),
        ]);

        return true;
    }
}
