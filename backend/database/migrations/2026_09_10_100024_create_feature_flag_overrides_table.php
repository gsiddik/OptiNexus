<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flag_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('feature_flag_id')->constrained('feature_flags')->cascadeOnDelete();
            $table->string('scope_type');
            $table->uuid('scope_id')->nullable();
            $table->jsonb('value');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('feature_flag_id');
            $table->index('scope_type');
            $table->index('scope_id');
            $table->unique(['feature_flag_id', 'scope_type', 'scope_id']);
        });

        // scope_id is null for GLOBAL overrides; Postgres treats NULLs as
        // distinct under a plain unique index, so a partial index enforces
        // at most one GLOBAL override per flag.
        DB::statement('CREATE UNIQUE INDEX feature_flag_overrides_global_unique ON feature_flag_overrides (feature_flag_id) WHERE scope_type = \'GLOBAL\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flag_overrides');
    }
};
