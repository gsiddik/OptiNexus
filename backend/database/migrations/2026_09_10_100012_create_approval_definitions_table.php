<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('definition_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('rule_type')->default('SEQUENTIAL');
            $table->string('status')->default('DRAFT');
            $table->boolean('self_approval_allowed')->default(false);
            $table->integer('expires_after_hours')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('application_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_definitions');
    }
};
