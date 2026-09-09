<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('flag_key')->unique();
            $table->foreignUuid('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('flag_type');
            $table->jsonb('default_value');
            $table->string('status')->default('DRAFT');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('application_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
