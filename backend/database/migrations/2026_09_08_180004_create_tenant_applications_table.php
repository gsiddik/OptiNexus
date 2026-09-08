<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('application_id')->constrained('applications')->restrictOnDelete();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            $table->unique(['tenant_id', 'application_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_applications');
    }
};
