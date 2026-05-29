<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerateRoutePatternsCommand extends Command
{
    protected $signature   = 'patterns:generate {--force : Drop and re-generate all existing patterns}';
    protected $description = 'Derive canonical route patterns from existing trip stop_times data';

    public function handle(): int
    {
        if ($this->option('force')) {
            DB::table('route_pattern_stops')->delete();
            DB::table('route_patterns')->delete();
            $this->info('Cleared existing patterns.');
        }

        $routeGroups = DB::table('trips')
            ->select('route_id', 'direction_id')
            ->distinct()
            ->orderBy('route_id')
            ->get();

        $this->info("Found {$routeGroups->count()} route/direction combinations.");

        $bar      = $this->output->createProgressBar($routeGroups->count());
        $bar->start();

        $created = 0;

        foreach ($routeGroups as $group) {
            $trips = DB::table('trips')
                ->where('route_id', $group->route_id)
                ->where('direction_id', $group->direction_id)
                ->pluck('trip_id');

            // Build a fingerprint (comma-joined stop_id sequence) for each trip
            $sequences = [];
            foreach ($trips as $tripId) {
                $stops = DB::table('stop_times')
                    ->where('trip_id', $tripId)
                    ->orderBy('stop_sequence')
                    ->pluck('stop_id')
                    ->toArray();

                if (empty($stops)) {
                    continue;
                }

                $fingerprint = implode(',', $stops);

                if (!isset($sequences[$fingerprint])) {
                    $sequences[$fingerprint] = [
                        'stops'     => $stops,
                        'count'     => 0,
                        'sample_id' => $tripId,
                    ];
                }
                $sequences[$fingerprint]['count']++;
            }

            if (empty($sequences)) {
                $bar->advance();
                continue;
            }

            // Sort by usage count descending; the most common is canonical
            uasort($sequences, fn ($a, $b) => $b['count'] <=> $a['count']);

            $isFirst = true;
            foreach ($sequences as $fingerprint => $seq) {
                $patternId = 'PAT_' . Str::upper(Str::random(8));

                $route = DB::table('routes')
                    ->where('route_id', $group->route_id)
                    ->first();

                $direction = $group->direction_id == 0 ? 'Outbound' : 'Inbound';
                $name      = ($route->route_short_name ?? $group->route_id)
                           . ' – ' . $direction
                           . ($isFirst ? '' : ' (variant)');

                DB::table('route_patterns')->insert([
                    'id'           => $patternId,
                    'route_id'     => $group->route_id,
                    'name'         => $name,
                    'direction_id' => (int) $group->direction_id,
                    'is_canonical' => $isFirst,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);

                $patternStops = [];
                foreach ($seq['stops'] as $seq_num => $stopId) {
                    $patternStops[] = [
                        'route_pattern_id' => $patternId,
                        'stop_id'          => $stopId,
                        'stop_sequence'    => $seq_num + 1,
                        'timepoint'        => true,
                        'pickup_type'      => 0,
                        'drop_off_type'    => 0,
                        'distance_traveled'=> null,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ];
                }

                foreach (array_chunk($patternStops, 500) as $chunk) {
                    DB::table('route_pattern_stops')->insert($chunk);
                }

                $created++;
                $isFirst = false;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Created {$created} route pattern(s).");

        return self::SUCCESS;
    }
}
