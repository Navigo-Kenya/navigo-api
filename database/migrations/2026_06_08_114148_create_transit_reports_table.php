<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transit_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            
            // The type of report (e.g., 'stage_queue', 'accident', 'police_check', 'flooded_route')
            $table->string('type', 50); 
            
            // PostGIS geometry column for the exact pin location
            $table->geometry('location', subtype: 'point', srid: 4326);
            
            // Crowdsourcing trust metrics
            $table->integer('upvotes')->default(0);
            $table->integer('downvotes')->default(0);
            
            // TTL Handling: 'active', 'expired', 'dismissed' (if too many downvotes)
            $table->string('status', 20)->default('active'); 
            
            // Crucial for map cleanliness: when does this report naturally die?
            $table->timestamp('expires_at'); 
            $table->timestamps();

            // Spatial index for lightning-fast bounding box queries
            $table->spatialIndex('location');
            
            // Compound index for the standard map fetch query
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transit_reports');
    }
};