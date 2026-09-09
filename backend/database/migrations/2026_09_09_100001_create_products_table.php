<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('product_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->char('currency', 3)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('product_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('application_id')->constrained('applications')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'application_id']);
        });

        Schema::create('product_capabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('capability_id')->constrained('capabilities')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'capability_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_capabilities');
        Schema::dropIfExists('product_applications');
        Schema::dropIfExists('products');
    }
};
