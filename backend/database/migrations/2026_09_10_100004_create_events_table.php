<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable fact log. The primary key IS the event_id: producers may
 * supply their own (for idempotent retries) or receive a server-generated
 * one; either way the PK's uniqueness is what "unique event_id" /
 * EVENT_DUPLICATE enforcement rests on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_key');
            $table->foreign('event_key')->references('event_key')->on('event_catalog');
            $table->string('event_version')->default('1');
            $table->timestamp('occurred_at');
            $table->foreignUuid('source_application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->foreignUuid('producer_service_account_id')->nullable()->constrained('service_accounts')->nullOnDelete();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('correlation_id')->nullable();
            $table->string('causation_id')->nullable();
            $table->jsonb('data');
            $table->timestamp('created_at')->useCurrent();

            $table->index('event_key');
            $table->index('tenant_id');
            $table->index('correlation_id');
            $table->index('occurred_at');
            $table->index('source_application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
