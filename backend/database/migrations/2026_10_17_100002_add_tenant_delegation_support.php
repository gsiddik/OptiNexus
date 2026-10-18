<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant delegation (audit R08/A25). Additive only.
 *
 * - permissions.delegable: the explicit set an Admin Tenant may hand out from a product. It defaults to false, so nothing is
 *   delegable until an operator (or the application manifest) says so; it is never derived from what a tenant admin holds.
 * - roles.origin / roles.created_by: tells roles created through the delegation API (editable by the tenant's admins, from the
 *   product) apart from roles an operator created in the portal (untouched by delegation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->boolean('delegable')->default(false)->after('status');
            $table->index(['application_id', 'delegable']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->string('origin', 20)->nullable()->after('is_system');
            $table->foreignUuid('created_by')->nullable()->after('origin')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Additive only: dropping these columns would silently widen what delegation roles can do.
    }
};
