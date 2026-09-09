<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('template_code')->unique();
            $table->string('name');
            $table->string('channel');
            $table->string('subject_template')->nullable();
            $table->text('body_template');
            $table->string('language')->default('en');
            $table->integer('version')->default(1);
            $table->string('status')->default('DRAFT');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('channel');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
