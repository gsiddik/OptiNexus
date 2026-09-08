<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->nullable()->constrained('applications')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->foreignUuid('oauth_client_id')->nullable()->unique()->constrained('oauth_clients')->nullOnDelete();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamp('last_used_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('service_account_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_account_id')->constrained('service_accounts')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX service_account_roles_global_unique ON service_account_roles (service_account_id, role_id) WHERE tenant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX service_account_roles_tenant_unique ON service_account_roles (service_account_id, role_id, tenant_id) WHERE tenant_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('service_account_roles');
        Schema::dropIfExists('service_accounts');
    }
};
