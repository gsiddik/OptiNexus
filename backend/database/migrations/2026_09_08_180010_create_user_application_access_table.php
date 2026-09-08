<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_application_access', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            $table->unique(['user_id', 'tenant_id', 'application_id']);
            $table->index(['tenant_id', 'application_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_application_access');
    }
};
