<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('workflow_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('status')->default('DRAFT');
            $table->integer('current_version')->default(0);
            $table->string('trigger_type');
            $table->string('trigger_event_key')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('application_id');
            $table->index('status');
            $table->index('trigger_type');
            $table->index('trigger_event_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};
