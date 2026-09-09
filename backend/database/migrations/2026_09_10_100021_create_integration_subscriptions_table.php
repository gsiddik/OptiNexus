<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->string('event_key');
            $table->foreign('event_key')->references('event_key')->on('event_catalog');
            $table->string('status')->default('ACTIVE');
            $table->timestamps();

            $table->unique(['integration_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_subscriptions');
    }
};
