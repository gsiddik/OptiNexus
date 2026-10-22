<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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
        // Existing Fleet installations with explicit read scopes need the new submit scope;
        // each submission still verifies the acting user's tenant/application permission.
        $appIds = DB::table('applications')->where('application_code', 'optifleet')->pluck('id');
        DB::table('service_accounts')->whereIn('application_id', $appIds)->whereNotNull('allowed_scopes')
            ->orderBy('id')->each(function ($account) {
                $scopes = json_decode($account->allowed_scopes, true);
                if (is_array($scopes) && in_array('commercial.read', $scopes, true) && ! in_array('commercial.payment.submit', $scopes, true)) {
                    DB::table('service_accounts')->where('id', $account->id)->update(['allowed_scopes' => json_encode([...$scopes, 'commercial.payment.submit'])]);
                }
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
