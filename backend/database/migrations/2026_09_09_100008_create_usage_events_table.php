<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('application_id')->constrained('applications')->restrictOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('meter_key');
            $table->decimal('quantity', 18, 4);
            $table->timestamp('usage_timestamp');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('source')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'meter_key', 'period_start']);
            $table->index(['subscription_id', 'meter_key']);
        });

        // Idempotency: the same (tenant, meter, idempotency_key) can only be
        // recorded once, so a retried ingestion request never double-bills.
        DB::statement('CREATE UNIQUE INDEX usage_events_idempotency_unique ON usage_events (tenant_id, meter_key, idempotency_key) WHERE idempotency_key IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
