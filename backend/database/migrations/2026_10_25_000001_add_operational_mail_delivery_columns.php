<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational e-mail completion (2026-10-25): an HTML part rendered next to the plain-text body, and a once-only stamp for the
 * invoice due-date reminder. Additive only; existing notifications keep sending as plain text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->text('body_html')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('due_reminder_sent_at')->nullable();
            $table->index(['status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_date']);
            $table->dropColumn('due_reminder_sent_at');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('body_html');
        });
    }
};
