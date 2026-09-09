<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_id')->constrained('workflows');
            $table->foreignUuid('workflow_version_id')->constrained('workflow_versions');
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('status')->default('PENDING');
            $table->string('trigger_type');
            $table->foreignUuid('trigger_event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->jsonb('trigger_payload')->nullable();
            $table->foreignUuid('current_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            $table->string('correlation_id');
            $table->string('causation_id')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->foreignUuid('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('workflow_id');
            $table->index('status');
            $table->index('correlation_id');
            $table->index('tenant_id');
        });

        // Duplicate-trigger protection: at most one instance per
        // (workflow_id, idempotency_key) when a key is supplied.
        DB::statement('CREATE UNIQUE INDEX workflow_instances_workflow_idem_unique ON workflow_instances (workflow_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_instances');
    }
};
