<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central logout and automatic deactivation.
 *
 * oidc_sessions remembers which applications a user has signed in to (the
 * `sid` claim of the id_token), so OptiNexus knows whom to tell when the user
 * logs out or loses access. oidc_logout_deliveries is the outbox of OIDC
 * Back-Channel Logout calls, with retries and an audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oidc_clients', function (Blueprint $table) {
            $table->string('backchannel_logout_uri', 2048)->nullable()->after('launch_url');
        });

        Schema::create('oidc_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('oidc_client_id')->constrained('oidc_clients')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 60)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'ended_at']);
            $table->index(['oidc_client_id', 'ended_at']);
            $table->index(['tenant_id', 'ended_at']);
        });

        Schema::table('oidc_access_tokens', function (Blueprint $table) {
            $table->foreignUuid('oidc_session_id')->nullable()->after('authorization_code_id')
                ->constrained('oidc_sessions')->nullOnDelete();
        });

        Schema::create('oidc_logout_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('oidc_client_id')->constrained('oidc_clients')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            // Null means every tenant of the user at that application.
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 20); // LOGOUT or ACCESS_REVOKED
            $table->string('reason', 60);
            $table->string('status', 20)->default('PENDING'); // PENDING, DELIVERED, FAILED
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('oidc_client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_logout_deliveries');
        Schema::table('oidc_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('oidc_session_id');
        });
        Schema::dropIfExists('oidc_sessions');
        Schema::table('oidc_clients', function (Blueprint $table) {
            $table->dropColumn('backchannel_logout_uri');
        });
    }
};
