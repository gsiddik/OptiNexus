<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 needs causation_id (which specific fact/action caused this
 * write) alongside the existing correlation_id (which end-to-end flow
 * this write belongs to) for orchestration traceability across Events,
 * Workflows, Approvals, Integrations, and Notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('causation_id')->nullable()->after('correlation_id');
            $table->index('causation_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['causation_id']);
            $table->dropColumn('causation_id');
        });
    }
};
