<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('billing_number')->unique();
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->restrictOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->char('currency', 3);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('adjustment_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->string('status', 20)->default('DRAFT');
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['subscription_id', 'period_start']);
        });

        // Only one non-cancelled billing per subscription+period - prevents
        // accidental duplicate billing runs for the same scope.
        DB::statement('CREATE UNIQUE INDEX billings_scope_period_unique ON billings (subscription_id, period_start, period_end) WHERE status <> \'CANCELLED\'');

        Schema::create('billing_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('billing_id')->constrained('billings')->cascadeOnDelete();
            $table->foreignUuid('subscription_item_id')->nullable()->constrained('subscription_items')->nullOnDelete();
            $table->string('charge_type', 20);
            $table->string('description');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_amount', 18, 2);
            $table->decimal('subtotal', 18, 2);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2);
            $table->jsonb('pricing_snapshot')->nullable();
            $table->uuid('usage_reference')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('billing_id');
        });

        Schema::create('billing_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('billing_id')->constrained('billings')->cascadeOnDelete();
            $table->string('adjustment_type', 20);
            $table->text('reason');
            $table->decimal('amount', 18, 2);
            $table->string('status', 20)->default('APPLIED');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('billing_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_adjustments');
        Schema::dropIfExists('billing_items');
        Schema::dropIfExists('billings');
    }
};
