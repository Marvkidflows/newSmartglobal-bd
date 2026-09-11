<?php
// LOCATION: database/migrations/2026_09_10_100001_create_competitions_table.php
//
// Gaming & Prediction — admin league selection.
//
// Previously "top leagues" was a fixed PHP constant (Fixture::LEAGUES /
// FootballDataService::COMPETITION_CODES) with exactly six leagues
// hardcoded and no admin control over which ones were active. This
// table replaces that constant as the source of truth so an admin can
// enable/disable competitions without a code change, per the "Admin
// should have control over which supported top leagues/competitions
// are active" requirement.
//
// Seeded with exactly the competitions football-data.org's free tier
// actually includes (verified against their current published plan —
// 12 competitions) — nothing invented beyond what the configured
// provider can really serve. See FootballDataService::CATALOG for the
// same list with provider codes attached.
//
// The original six (Premier League, La Liga, Serie A, Bundesliga,
// Ligue 1, Champions League) are seeded enabled=true so existing
// behavior is unchanged for anyone upgrading; the other six free-tier
// competitions are seeded enabled=false — present and one click away,
// not silently turned on.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();     // e.g. "Premier League"
            $table->string('provider_code', 10)->unique(); // football-data.org competition code, e.g. "PL"
            $table->boolean('is_enabled')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed from the same catalog FootballDataService uses, so the
        // table and the provider integration never drift apart.
        $now = now();
        $rows = collect(\App\Services\FootballDataService::CATALOG)
            ->values()
            ->map(function ($entry, $i) use ($now) {
                return [
                    'name'          => $entry['name'],
                    'provider_code' => $entry['code'],
                    'is_enabled'    => $entry['enabled_by_default'],
                    'sort_order'    => $i,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            })->all();

        DB::table('competitions')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('competitions');
    }
};
