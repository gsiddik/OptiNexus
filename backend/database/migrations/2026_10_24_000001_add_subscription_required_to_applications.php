<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NULL = follow the catalog (required once the application is sold through a product, plan or add-on);
        // true/false = explicit platform decision. Replaces the hard-coded list in SubscriptionAccess::required().
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('subscription_required')->nullable();
        });
        // Keep the three applications that were already required, and make the audit's Radar/Deli gap explicit.
        DB::table('applications')->whereIn('application_code', ['optifleet', 'optiaccounting', 'optientry', 'optiradar', 'optideli'])
            ->update(['subscription_required' => true]);
        // The governance portal is never sold.
        DB::table('applications')->where('application_code', 'cgo')->update(['subscription_required' => false]);
    }

    public function down(): void
    {
        Schema::table('applications', fn (Blueprint $table) => $table->dropColumn('subscription_required'));
    }
};
