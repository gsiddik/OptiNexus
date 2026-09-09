<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('approval_definition_id')->constrained('approval_definitions')->cascadeOnDelete();
            $table->integer('level_order');
            $table->string('name');
            $table->string('approver_type');
            $table->string('approver_reference')->nullable();
            $table->jsonb('condition')->nullable();
            $table->timestamps();

            $table->unique(['approval_definition_id', 'level_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_levels');
    }
};
