<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('application_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('owner')->nullable();
            $table->string('version')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->string('frontend_url')->nullable();
            $table->string('backend_url')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
