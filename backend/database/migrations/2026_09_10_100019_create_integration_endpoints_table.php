<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->string('endpoint_key');
            $table->string('method');
            $table->string('path');
            $table->jsonb('headers_template')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'endpoint_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_endpoints');
    }
};
