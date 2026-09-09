<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('addon_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('addon_capabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('addon_id')->constrained('addons')->cascadeOnDelete();
            $table->foreignUuid('capability_id')->constrained('capabilities')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['addon_id', 'capability_id']);
        });

        Schema::create('addon_limits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('addon_id')->constrained('addons')->cascadeOnDelete();
            $table->string('limit_key');
            $table->decimal('limit_delta', 18, 4);
            $table->string('unit')->nullable();
            $table->timestamps();

            $table->unique(['addon_id', 'limit_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_limits');
        Schema::dropIfExists('addon_capabilities');
        Schema::dropIfExists('addons');
    }
};
