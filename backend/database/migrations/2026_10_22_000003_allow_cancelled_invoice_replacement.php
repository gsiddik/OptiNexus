<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_billing_id_unique');
        DB::statement("CREATE UNIQUE INDEX invoices_one_current_per_billing ON invoices (billing_id) WHERE status <> 'CANCELLED'");
        DB::statement('UPDATE invoices SET issue_date = issued_at::date WHERE issue_date IS NULL AND issued_at IS NOT NULL');
    }

    public function down(): void
    {
        // Never delete historical replacement invoices to make a rollback fit.
        if (DB::table('invoices')->select('billing_id')->groupBy('billing_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore one-invoice-per-billing constraint while replacement history exists.');
        }
        DB::statement('DROP INDEX invoices_one_current_per_billing');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_billing_id_unique UNIQUE (billing_id)');
    }
};
