<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_catalog', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_key')->unique();
            $table->foreignUuid('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('schema_version')->default('1');
            $table->jsonb('payload_schema')->nullable();
            $table->string('status')->default('ACTIVE');
            $table->timestamps();

            $table->index('application_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_catalog');
    }
};
