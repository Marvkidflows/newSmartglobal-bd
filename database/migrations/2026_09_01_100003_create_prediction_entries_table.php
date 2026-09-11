<?php
// LOCATION: database/migrations/2026_09_01_100003_create_prediction_entries_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prediction_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('choice', ['up', 'down']);
            $table->unsignedInteger('points_staked'); // copied from the round at entry time — never currency
            $table->boolean('is_correct')->nullable();       // null until the round resolves
            $table->unsignedInteger('points_awarded')->nullable();
            $table->dateTime('submitted_at');
            $table->timestamps();

            // One prediction per investor per round — enforced here at the
            // DB level, not just in the controller.
            $table->unique(['prediction_round_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_entries');
    }
};
