<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('rule_code')->unique();
            $table->string('name');
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('trigger_event_key')->nullable();
            $table->foreign('trigger_event_key')->references('event_key')->on('event_catalog');
            $table->jsonb('condition')->nullable();
            $table->string('recipient_type');
            $table->string('recipient_reference')->nullable();
            $table->foreignUuid('notification_template_id')->constrained('notification_templates');
            $table->string('channel');
            $table->string('status')->default('ACTIVE');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('trigger_event_key');
            $table->index('tenant_id');
            $table->index('application_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rules');
    }
};
