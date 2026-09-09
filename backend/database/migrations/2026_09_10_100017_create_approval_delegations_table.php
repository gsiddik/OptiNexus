<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('delegator_user_id')->constrained('users');
            $table->foreignUuid('delegate_user_id')->constrained('users');
            $table->foreignUuid('approval_definition_id')->nullable()->constrained('approval_definitions')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status')->default('ACTIVE');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();

            $table->index('delegator_user_id');
            $table->index('delegate_user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_delegations');
    }
};
