<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Server-side, concurrency-safe document numbering for commercial records.
 * A Postgres SEQUENCE guarantees no two concurrent requests ever receive
 * the same number, without needing application-level locking.
 */
return new class extends Migration
{
    public function up(): void
    {
        // IF NOT EXISTS: `migrate:fresh` drops all tables via a driver-level
        // "drop everything" command that does not run migration down()
        // steps, so a standalone (table-unowned) sequence from a previous
        // run would otherwise survive and collide with this CREATE.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS subscription_number_seq START 1');
        DB::statement('CREATE SEQUENCE IF NOT EXISTS billing_number_seq START 1');
        DB::statement('CREATE SEQUENCE IF NOT EXISTS invoice_number_seq START 1');
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS subscription_number_seq');
        DB::statement('DROP SEQUENCE IF EXISTS billing_number_seq');
        DB::statement('DROP SEQUENCE IF EXISTS invoice_number_seq');
    }
};
