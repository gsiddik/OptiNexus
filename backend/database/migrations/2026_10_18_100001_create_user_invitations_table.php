<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding by invitation (owner decision 2026-10-18). Additive only.
 *
 * An invitation grants nothing by itself: it records WHO may be added (e-mail), WHERE (tenant + application), WITH WHICH roles
 * and who authorised it. Identity, membership, application access and roles are created when the invitee accepts, after a full
 * re-validation. Only the SHA-256 of the token is stored; the token itself exists in the e-mail only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('email');
            $table->string('name');
            $table->string('locale', 5)->default('en');
            $table->jsonb('role_ids')->default('[]');
            $table->char('token_hash', 64)->unique();
            $table->string('status', 20)->default('PENDING');
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedSmallInteger('send_count')->default(0);
            $table->string('delivery_status', 20)->default('QUEUED');
            $table->string('delivery_error_code', 40)->nullable();
            $table->timestamp('delivery_attempted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUuid('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'application_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        // One open invitation per person, tenant and application; a concurrent duplicate create hits this constraint.
        DB::statement("CREATE UNIQUE INDEX user_invitations_open_unique ON user_invitations (tenant_id, application_id, lower(email)) WHERE status = 'PENDING'");
        DB::statement("ALTER TABLE user_invitations ADD CONSTRAINT user_invitations_status_check CHECK (status IN ('PENDING','ACCEPTED','REVOKED','EXPIRED'))");
        DB::statement("ALTER TABLE user_invitations ADD CONSTRAINT user_invitations_delivery_check CHECK (delivery_status IN ('QUEUED','SENT','FAILED'))");
        DB::statement('ALTER TABLE user_invitations ADD CONSTRAINT user_invitations_email_lower_check CHECK (email = lower(email))');
    }

    public function down(): void
    {
        // Additive only: invitations are audit evidence of who was allowed in.
    }
};
