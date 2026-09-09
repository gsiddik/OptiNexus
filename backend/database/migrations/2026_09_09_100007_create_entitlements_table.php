<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignUuid('application_id')->nullable()->constrained('applications')->cascadeOnDelete();
            $table->foreignUuid('capability_id')->nullable()->constrained('capabilities')->cascadeOnDelete();
            $table->string('entitlement_type', 20);
            $table->string('entitlement_key');
            $table->string('value');
            $table->string('status', 20)->default('ACTIVE');
            $table->string('source_type', 20);
            $table->uuid('source_reference_id')->nullable();
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_until')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'entitlement_key', 'status']);
            $table->index(['tenant_id', 'application_id', 'status']);
            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlements');
    }
};
