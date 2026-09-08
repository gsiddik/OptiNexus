<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('applications')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('type', 20);
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('status', 20)->default('ACTIVE');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['application_id', 'code']);
            $table->index(['application_id', 'parent_id']);
            $table->index('type');
            $table->index('status');
        });

        Schema::table('capabilities', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('capabilities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capabilities');
    }
};
