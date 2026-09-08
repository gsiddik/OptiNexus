<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('application_id')->nullable()->constrained('applications')->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->string('role_type', 20);
            $table->boolean('is_system')->default(false);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('role_type');
        });

        // Partial unique indexes: code must be unique within a tenant, and
        // globally unique among non-tenant-scoped (SYSTEM/APPLICATION) roles.
        DB::statement('CREATE UNIQUE INDEX roles_code_global_unique ON roles (code) WHERE tenant_id IS NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX roles_code_tenant_unique ON roles (tenant_id, code) WHERE tenant_id IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
