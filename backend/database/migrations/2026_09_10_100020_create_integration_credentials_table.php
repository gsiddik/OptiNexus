<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->string('credential_type');
            // Laravel `encrypted` cast (AES-256-CBC via APP_KEY) - the secret
            // is never stored or returned in plaintext once written.
            $table->text('encrypted_secret');
            $table->string('reference_label');
            $table->string('status')->default('ACTIVE');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('integration_id');
            $table->index('status');
        });

        // At most one ACTIVE credential per integration at a time.
        DB::statement('CREATE UNIQUE INDEX integration_credentials_one_active ON integration_credentials (integration_id) WHERE status = \'ACTIVE\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_credentials');
    }
};
