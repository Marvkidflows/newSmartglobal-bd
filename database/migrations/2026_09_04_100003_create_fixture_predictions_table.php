<?php
// LOCATION: database/migrations/2026_09_04_100003_create_fixture_predictions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixture_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixture_market_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Selection values depend on market_type:
            //   one_x_two:      home | draw | away
            //   double_chance:  home_or_draw | draw_or_away | home_or_away
            //   over_under:     over | under
            //   handicap:       home | away
            $table->string('selection', 20);
            $table->boolean('is_correct')->nullable();
            $table->unsignedInteger('points_awarded')->nullable();
            $table->dateTime('submitted_at');
            $table->timestamps();

            // One prediction per investor per market — no real money is
            // involved, but a single free pick per market keeps this an
            // actual prediction rather than a hedge-every-outcome loophole.
            $table->unique(['fixture_market_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_predictions');
    }
};
