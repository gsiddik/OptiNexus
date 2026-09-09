<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Append-only: one decision per approval_request_step, never mutated. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('approval_request_step_id')->unique()->constrained('approval_request_steps')->cascadeOnDelete();
            $table->foreignUuid('approval_request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->foreignUuid('decided_by')->constrained('users');
            $table->string('decision');
            $table->text('comment')->nullable();
            $table->timestamp('decided_at')->useCurrent();

            $table->index('approval_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_decisions');
    }
};
