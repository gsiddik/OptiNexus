<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * First Admin Tenant without e-mail (owner decision 2026-10-18, second batch). Additive only.
 *
 * An operator creates the account, records the out-of-band check of the e-mail address and hands over a temporary password. This
 * migration only adds the state that flow needs on `users`; nothing existing changes meaning and every new column is nullable or
 * defaulted, so accounts that never go through the flow behave exactly as before (activation_started_at stays NULL).
 *
 * The temporary password itself is the account's normal (hashed) password; only its lifecycle is tracked here. The reason given
 * for a manual verification is audit evidence (audit_logs), not a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Managed activation: set when an operator creates the account through the bootstrap flow, completed when the e-mail is
            // verified AND the temporary password was replaced. While it is open the account stays INVITED (no access anywhere).
            $table->timestamp('activation_started_at')->nullable();
            $table->timestamp('activated_at')->nullable();

            $table->boolean('password_change_required')->default(false);
            $table->timestamp('temporary_password_issued_at')->nullable();
            $table->timestamp('temporary_password_expires_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();

            // How and by whom the address was verified. email_verified_at keeps its meaning; these two say where it came from.
            $table->string('email_verification_method', 20)->nullable();
            $table->foreignUuid('email_verified_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_email_verification_method_check CHECK (email_verification_method IS NULL OR email_verification_method IN ('INVITATION','OPERATOR_MANUAL'))");
        // A verification method only makes sense on a verified address, and a temporary password always has an expiry.
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_verification_method_requires_verified_check CHECK (email_verification_method IS NULL OR email_verified_at IS NOT NULL)');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_temporary_password_expiry_check CHECK (password_change_required = false OR temporary_password_expires_at IS NOT NULL)');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_activated_after_started_check CHECK (activated_at IS NULL OR activation_started_at IS NOT NULL)');
    }

    public function down(): void
    {
        // Additive only: the columns are evidence of how an account was activated.
    }
};
