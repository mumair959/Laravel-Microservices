<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('processed_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique(); // Ensures no duplicate processing
            $table->string('event_type');
            $table->integer('order_id');
            $table->integer('user_id');
            $table->json('payload')->nullable();
            $table->timestamp('processed_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processed_events');
    }
};
