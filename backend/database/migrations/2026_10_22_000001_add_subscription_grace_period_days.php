<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('grace_period_days')->default(0);
        });
        // Preserve a previously configured grace end when upgrading existing subscriptions.
        DB::statement("UPDATE subscriptions SET grace_period_days = GREATEST(0, CEIL(EXTRACT(EPOCH FROM (grace_end_at - current_period_end)) / 86400)) WHERE grace_end_at IS NOT NULL AND current_period_end IS NOT NULL");
        DB::statement("UPDATE subscriptions SET grace_end_at = current_period_end + (grace_period_days * INTERVAL '1 day') WHERE grace_end_at IS NULL AND current_period_end IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('grace_period_days'));
    }
};
