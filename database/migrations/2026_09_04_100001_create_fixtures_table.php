<?php
// LOCATION: database/migrations/2026_09_04_100001_create_fixtures_table.php
//
// Gaming & Prediction (sports fixtures) — final spec replacing the earlier
// crypto up/down prediction module. Non-wagering: points only, never
// wallet/balance, same invariant as the module it replaces. See
// AdminFixtureController's header comment for full scope notes.
//
// Fixtures are entered manually by admin — no live sports-data provider
// is configured in this codebase, and one was not fabricated. "Top
// leagues only" is enforced by restricting `league` to a fixed list
// (see Fixture::LEAGUES) rather than trusting free text.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixtures', function (Blueprint $table) {
            $table->id();
            $table->string('league', 60);       // restricted to a fixed top-leagues list — see Fixture::LEAGUES
            $table->string('home_team', 100);
            $table->string('away_team', 100);
            $table->dateTime('kickoff_at');
            $table->enum('status', ['scheduled', 'live', 'finished', 'cancelled'])->default('scheduled');
            $table->unsignedTinyInteger('home_score')->nullable();
            $table->unsignedTinyInteger('away_score')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'kickoff_at']);
            $table->index('league');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixtures');
    }
};
