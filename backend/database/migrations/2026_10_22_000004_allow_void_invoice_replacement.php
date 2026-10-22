<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX invoices_one_current_per_billing');
        DB::statement("CREATE UNIQUE INDEX invoices_one_current_per_billing ON invoices (billing_id) WHERE status NOT IN ('CANCELLED', 'VOID')");
    }

    public function down(): void
    {
        if (DB::table('invoices')->where('status', '!=', 'CANCELLED')->groupBy('billing_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot roll back while void invoice replacement history exists.');
        }
        DB::statement('DROP INDEX invoices_one_current_per_billing');
        DB::statement("CREATE UNIQUE INDEX invoices_one_current_per_billing ON invoices (billing_id) WHERE status <> 'CANCELLED'");
    }
};
