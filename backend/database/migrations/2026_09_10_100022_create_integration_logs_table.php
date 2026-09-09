<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->foreignUuid('workflow_instance_step_id')->nullable()->constrained('workflow_instance_steps')->nullOnDelete();
            $table->string('correlation_id')->nullable();
            $table->string('direction')->default('OUTBOUND');
            $table->jsonb('request_summary')->nullable();
            $table->integer('response_status')->nullable();
            $table->jsonb('response_summary')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('status');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('attempt_count')->default(1);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('integration_id');
            $table->index('correlation_id');
            $table->index('status');
            $table->index('next_retry_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
    }
};
