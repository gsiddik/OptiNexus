<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * API Gateway storage: the fleet vehicle directory published by fleet apps,
 * the vehicle <-> telematics device links, the append-only odometer feed and
 * a per-request log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_fleet_vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('external_vehicle_id');
            $table->string('registration_number');
            $table->string('normalized_registration')->index();
            $table->string('vin')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            $table->unique(['tenant_id', 'application_id', 'external_vehicle_id'], 'gateway_fleet_vehicles_unique');
        });

        Schema::create('gateway_vehicle_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('fleet_vehicle_id')->nullable()->constrained('gateway_fleet_vehicles')->nullOnDelete();
            $table->string('telematics_source', 40);
            $table->string('device_ref');
            $table->string('device_name')->nullable();
            $table->string('device_registration')->nullable();
            $table->string('link_type', 10)->default('AUTO');
            $table->string('status', 20)->default('ACTIVE');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'telematics_source', 'device_ref'], 'gateway_vehicle_links_device_unique');
            $table->index(['tenant_id', 'fleet_vehicle_id']);
        });

        // Monotonic feed position. A reading gets a new seq when first stored and
        // again when its device is linked to a vehicle, so a consumer that already
        // passed it still receives the latest value of a newly linked device.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS gateway_odometer_seq');

        Schema::create('gateway_odometer_readings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seq')->index();
            $table->uuid('reading_id')->unique();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('source', 40);
            $table->string('device_ref');
            $table->decimal('odometer_km', 12, 2);
            // DEVICE_ODOMETER: the vehicle's real odometer. GPS_DISTANCE: distance accumulated by the
            // tracker since installation (not comparable to the dashboard odometer without calibration).
            $table->string('odometer_kind', 20)->default('DEVICE_ODOMETER');
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'source', 'device_ref', 'recorded_at'], 'gateway_odometer_readings_dedupe');
            $table->index(['tenant_id', 'seq']);
        });

        Schema::create('gateway_request_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->foreignUuid('service_account_id')->nullable()->constrained('service_accounts')->nullOnDelete();
            $table->string('method', 10);
            $table->string('path');
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms');
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_request_logs');
        Schema::dropIfExists('gateway_odometer_readings');
        DB::statement('DROP SEQUENCE IF EXISTS gateway_odometer_seq');
        Schema::dropIfExists('gateway_vehicle_links');
        Schema::dropIfExists('gateway_fleet_vehicles');
    }
};
