<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_request_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('approval_request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->foreignUuid('approval_level_id')->constrained('approval_levels');
            $table->integer('level_order');
            $table->foreignUuid('resolved_approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('PENDING');
            $table->timestamps();

            $table->unique(['approval_request_id', 'level_order']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_request_steps');
    }
};
