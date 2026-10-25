<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses the mail provider stopped delivering to (hard bounce, spam complaint, manual suppression), reported by its webhook.
 * Platform-level: an e-mail address is a global identity, not a tenant resource. One row per address; a release keeps the row
 * (released_at) so the history stays visible, and a later bounce re-suppresses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Stored lower-cased.
            $table->string('email')->unique();
            $table->string('reason', 40);
            $table->string('provider', 40);
            $table->string('provider_message_id')->nullable();
            $table->string('description', 500)->nullable();
            $table->timestamp('suppressed_at');
            $table->timestamp('released_at')->nullable();
            $table->foreignUuid('released_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index('released_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
