<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->integer('version');
            $table->string('status')->default('DRAFT');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['workflow_id', 'version']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_versions');
    }
};
