<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_instance_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->foreignUuid('workflow_step_id')->constrained('workflow_steps');
            $table->string('status')->default('PENDING');
            $table->integer('attempt_count')->default(0);
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->jsonb('input')->nullable();
            $table->jsonb('output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('workflow_instance_id');
            $table->index('status');
            $table->index('next_retry_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_instance_steps');
    }
};
