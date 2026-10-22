<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->string('status', 20)->default('VERIFIED');
            $table->string('bank_name')->nullable();
            $table->string('account_name')->nullable();
            $table->text('note')->nullable();
            $table->text('verification_note')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->string('submission_key', 100)->nullable()->unique();
            $table->jsonb('proof')->nullable();
            $table->index(['invoice_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropIndex(['invoice_id', 'status']);
            $table->dropColumn(['status', 'bank_name', 'account_name', 'note', 'verification_note', 'verified_at', 'reviewed_by', 'submission_key', 'proof']);
        });
    }
};
