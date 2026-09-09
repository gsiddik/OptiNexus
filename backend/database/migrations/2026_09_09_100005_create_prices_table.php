<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->nullable()->constrained('plans')->cascadeOnDelete();
            $table->foreignUuid('addon_id')->nullable()->constrained('addons')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('price_type', 20);
            $table->char('currency', 3);
            $table->decimal('unit_amount', 18, 2)->nullable();
            $table->string('billing_interval', 20);
            $table->string('unit_name')->nullable();
            $table->string('meter_key')->nullable();
            $table->decimal('minimum_quantity', 18, 4)->nullable();
            $table->decimal('included_quantity', 18, 4)->nullable();
            $table->foreignUuid('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->string('status', 20)->default('ACTIVE');
            $table->string('approval_status', 20)->default('APPROVED');
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_until')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['plan_id', 'tenant_id', 'status']);
            $table->index(['addon_id', 'status']);
            $table->index('meter_key');
        });

        // A price must belong to a plan or an addon (never neither, never both).
        DB::statement('ALTER TABLE prices ADD CONSTRAINT prices_plan_xor_addon CHECK ((plan_id IS NOT NULL)::int + (addon_id IS NOT NULL)::int = 1)');

        Schema::create('pricing_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('price_id')->constrained('prices')->cascadeOnDelete();
            $table->unsignedInteger('tier_order');
            $table->decimal('from_quantity', 18, 4);
            $table->decimal('to_quantity', 18, 4)->nullable();
            $table->decimal('unit_amount', 18, 2);
            $table->decimal('flat_amount', 18, 2)->nullable();
            $table->timestamps();

            $table->unique(['price_id', 'tier_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_tiers');
        Schema::dropIfExists('prices');
    }
};
