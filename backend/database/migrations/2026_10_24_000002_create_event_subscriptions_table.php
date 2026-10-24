<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Event broker (ecosystem audit G4, owner decision K4): an application subscribes to event keys and receives them as signed
 * webhooks, only for tenants it is assigned to. Deliveries reuse event_deliveries (consumer_type WEBHOOK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('applications');
            // Exact key ("optifleet.vehicle.status_changed") or a prefix ending in ".*" ("optifleet.vehicle.*").
            $table->string('event_key_pattern', 150);
            // Null = every tenant the subscriber is assigned to; otherwise this tenant only.
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $table->string('endpoint_url', 2048);
            $table->text('signing_secret');
            $table->string('status')->default('ACTIVE');
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamp('secret_rotated_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'event_key_pattern']);
        });

        // One subscription per application, pattern and tenant scope (NULL tenant counted once).
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX event_subscriptions_scope_unique ON event_subscriptions (application_id, event_key_pattern, COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'::uuid))");
        } else {
            Schema::table('event_subscriptions', fn (Blueprint $table) => $table->unique(['application_id', 'event_key_pattern', 'tenant_id']));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_subscriptions');
    }
};
