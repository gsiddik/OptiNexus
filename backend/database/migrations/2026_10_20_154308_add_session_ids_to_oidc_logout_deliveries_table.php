<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('oidc_logout_deliveries', function (Blueprint $table) {
            $table->jsonb('session_ids')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oidc_logout_deliveries', function (Blueprint $table) {
            $table->dropColumn('session_ids');
        });
    }
};
