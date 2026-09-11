<?php
// LOCATION: database/migrations/2026_09_04_100002_create_fixture_markets_table.php
//
// A "market" is one of the four allowed prediction types attached to a
// fixture: 1X2, Double Chance, Over/Under, Handicap. `line` holds the
// numeric threshold Over/Under and Handicap need (e.g. 2.5 goals, -1.5
// handicap) and is unused/null for 1X2 and Double Chance.
//
// ONLY these four market_type values are ever created — enforced by the
// enum column AND by AdminFixtureController's validation — per the
// explicit "do not add extra prediction markets" requirement.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixture_markets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixture_id')->constrained()->cascadeOnDelete();
            $table->enum('market_type', ['one_x_two', 'double_chance', 'over_under', 'handicap']);
            $table->decimal('line', 4, 1)->nullable(); // e.g. 2.5 for O/U, -1.5 for handicap
            $table->enum('status', ['open', 'closed', 'settled'])->default('open');
            $table->timestamps();

            // Only one of each market type per fixture — prevents an admin
            // from accidentally creating two conflicting "1X2" markets
            // (which line/selections would then apply to which?) on the
            // same fixture.
            $table->unique(['fixture_id', 'market_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_markets');
    }
};
