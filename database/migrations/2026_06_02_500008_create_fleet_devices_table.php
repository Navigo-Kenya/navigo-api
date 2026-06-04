<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fleet_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('vehicle_id');
            $table->string('device_type', 50);  // gps_tracker / fuel_sensor / dash_cam / panic_button / eld / custom
            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('imei', 20)->nullable();
            $table->string('protocol', 50)->nullable(); // teltonika / traccar / http_webhook / gt06 / etc.
            $table->string('ingest_token', 64)->unique(); // used to authenticate device pushes
            $table->string('server_ip', 45)->nullable();
            $table->unsignedSmallInteger('server_port')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('meta')->nullable(); // protocol-specific config
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('added_by')->nullable();
            $table->timestamps();

            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('added_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['vehicle_id', 'device_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_devices');
    }
};
