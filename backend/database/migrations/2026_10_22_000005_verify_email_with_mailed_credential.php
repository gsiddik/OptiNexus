<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('verify_email_on_password_change')->default(false);
        });
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_email_verification_method_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_email_verification_method_check CHECK (email_verification_method IS NULL OR email_verification_method IN ('INVITATION','OPERATOR_MANUAL','EMAIL_CREDENTIAL'))");
    }

    public function down(): void
    {
        // Forward-only: retain evidence of how an address was verified.
    }
};
