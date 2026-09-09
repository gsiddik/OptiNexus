<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events');
            $table->string('consumer_type');
            $table->string('consumer_reference')->nullable();
            $table->string('status')->default('PENDING');
            $table->integer('attempt_count')->default(0);
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index('event_id');
            $table->index('status');
            $table->index('next_retry_at');
            $table->index('consumer_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_deliveries');
    }
};
