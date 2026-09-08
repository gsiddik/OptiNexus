<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignUuid('capability_id')->nullable()->constrained('capabilities')->nullOnDelete();
            $table->string('permission_key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            $table->index('status');
            $table->index('application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
