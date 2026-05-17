<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cached_walking_routes', function (Blueprint $table) {
            $table->id();
            // Rounded to 4 decimal places (~11 m buckets) so nearby
            // start/end points reuse the same cached route.
            $table->decimal('from_lat', 9, 4);
            $table->decimal('from_lng', 9, 4);
            $table->decimal('to_lat',   9, 4);
            $table->decimal('to_lng',   9, 4);
            $table->jsonb('coordinates'); // [[lat, lng], ...] road-snapped
            $table->jsonb('walk_steps'); // [{instruction, distance, lat, lng}]
            $table->integer('distance_m');
            $table->integer('duration_s');
            $table->timestamps();

            $table->unique(
                ['from_lat', 'from_lng', 'to_lat', 'to_lng'],
                'uniq_walk_route'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cached_walking_routes');
    }
};
