<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_version_id')->constrained('workflow_versions')->cascadeOnDelete();
            $table->foreignUuid('from_step_id')->constrained('workflow_steps')->cascadeOnDelete();
            $table->foreignUuid('to_step_id')->constrained('workflow_steps')->cascadeOnDelete();
            // Reuses the same safe declarative grammar as Policy
            // (PolicyConditionEvaluator) - null means "always take this
            // transition" (the default/else branch out of a CONDITION step).
            $table->jsonb('condition')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('workflow_version_id');
            $table->index('from_step_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transitions');
    }
};
