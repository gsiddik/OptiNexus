<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_version_id')->constrained('workflow_versions')->cascadeOnDelete();
            $table->string('step_key');
            $table->string('step_type');
            $table->string('name');
            $table->jsonb('config')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['workflow_version_id', 'step_key']);
            $table->index('step_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_steps');
    }
};
