<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-service-account scope allow-list (audit A23): a token may only exercise scopes its own service account is allowed to hold.
 * NULL keeps the earlier behaviour (any registered scope) so existing accounts continue to work until an operator narrows them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_accounts', function (Blueprint $table) {
            $table->json('allowed_scopes')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('service_accounts', function (Blueprint $table) {
            $table->dropColumn('allowed_scopes');
        });
    }
};
