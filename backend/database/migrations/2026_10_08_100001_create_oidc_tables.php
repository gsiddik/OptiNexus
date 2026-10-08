<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OpenID Connect provider storage. OptiNexus is the identity provider for
 * every integrated application (OptiFleet, OptiRadar, ...). Secrets, codes
 * and access tokens are stored only as SHA-256 hashes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('applications')->restrictOnDelete();
            $table->string('client_id')->unique();
            $table->string('client_secret_hash')->nullable();
            $table->string('name');
            $table->jsonb('redirect_uris');
            $table->jsonb('post_logout_redirect_uris')->nullable();
            $table->string('launch_url')->nullable();
            $table->boolean('require_pkce')->default(false);
            $table->string('status', 20)->default('ACTIVE');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('application_id');
            $table->index('status');
        });

        Schema::create('oidc_authorization_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code_hash', 64)->unique();
            $table->foreignUuid('oidc_client_id')->constrained('oidc_clients')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->text('redirect_uri');
            $table->jsonb('scopes');
            $table->string('nonce')->nullable();
            $table->string('code_challenge')->nullable();
            $table->string('code_challenge_method', 10)->nullable();
            $table->timestamp('auth_time');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('oidc_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token_hash', 64)->unique();
            $table->foreignUuid('oidc_client_id')->constrained('oidc_clients')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('authorization_code_id')->nullable()->constrained('oidc_authorization_codes')->nullOnDelete();
            $table->jsonb('scopes');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'oidc_client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_access_tokens');
        Schema::dropIfExists('oidc_authorization_codes');
        Schema::dropIfExists('oidc_clients');
    }
};
