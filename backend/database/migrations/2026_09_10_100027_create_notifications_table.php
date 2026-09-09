<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single addressed notification message (one recipient, one channel).
 * Delivery history (retry attempts) lives in notification_deliveries;
 * this row tracks current state, matching the spec's Delivery states
 * directly on the message since the API only exposes /notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->foreignUuid('notification_rule_id')->nullable()->constrained('notification_rules')->nullOnDelete();
            $table->foreignUuid('notification_template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
            $table->foreignUuid('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel_target')->nullable();
            $table->string('channel');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('status')->default('QUEUED');
            $table->string('correlation_id')->nullable();
            $table->string('causation_id')->nullable();
            $table->integer('attempt_count')->default(0);
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('application_id');
            $table->index('recipient_user_id');
            $table->index('status');
            $table->index('correlation_id');
            $table->index('next_retry_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
