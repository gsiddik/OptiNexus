<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Event ids are idempotent per producing application, not globally (audit, wave 6): `producer_event_id` is the id the producer sent
 * and `(source_application_id, producer_event_id)` is unique. `events.id` stays Nexus's own unique id (consumers de-duplicate on it), and
 * equals the producer's id whenever that id is free, so existing producers see no change. Existing rows: producer_event_id = id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->uuid('producer_event_id')->nullable();
        });

        DB::table('events')->whereNull('producer_event_id')->update(['producer_event_id' => DB::raw('id')]);

        Schema::table('events', function (Blueprint $table) {
            $table->unique(['source_application_id', 'producer_event_id'], 'events_source_producer_event_unique');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique('events_source_producer_event_unique');
            $table->dropColumn('producer_event_id');
        });
    }
};
