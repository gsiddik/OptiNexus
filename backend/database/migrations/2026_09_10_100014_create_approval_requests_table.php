<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('approval_definition_id')->constrained('approval_definitions');
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->foreignUuid('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('status')->default('PENDING');
            $table->integer('current_level')->default(1);
            $table->jsonb('context')->nullable();
            $table->string('correlation_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('status');
            $table->index('correlation_id');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
