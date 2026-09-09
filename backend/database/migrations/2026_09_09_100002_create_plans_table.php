<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->string('plan_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('billing_interval', 20);
            $table->string('status', 20)->default('DRAFT');
            $table->unsignedInteger('trial_days')->nullable();
            $table->char('currency', 3);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'status']);
        });

        Schema::create('plan_capabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignUuid('capability_id')->constrained('capabilities')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['plan_id', 'capability_id']);
        });

        Schema::create('plan_limits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('limit_key');
            $table->decimal('limit_value', 18, 4)->nullable();
            $table->boolean('is_unlimited')->default(false);
            $table->string('unit')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'limit_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_limits');
        Schema::dropIfExists('plan_capabilities');
        Schema::dropIfExists('plans');
    }
};
