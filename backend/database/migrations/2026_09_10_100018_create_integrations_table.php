<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('integration_code')->unique();
            $table->string('name');
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('source_application_id')->constrained('applications');
            $table->foreignUuid('target_application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('integration_type');
            $table->string('status')->default('DRAFT');
            $table->string('base_url')->nullable();
            $table->integer('timeout_seconds')->default(30);
            $table->jsonb('retry_policy')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('source_application_id');
            $table->index('status');
            $table->index('integration_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
