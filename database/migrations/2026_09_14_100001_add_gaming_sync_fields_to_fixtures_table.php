<?php
// LOCATION: database/migrations/2026_09_14_100001_add_gaming_sync_fields_to_fixtures_table.php
//
// Gaming & Prediction — automatic result/status synchronization support.
//
// 1) `status` was a fixed DB enum (scheduled/live/finished/cancelled).
//    Automatic sync needs a fifth value, 'postponed' (a provider match
//    marked POSTPONED or SUSPENDED — different from 'cancelled', which
//    means the match is permanently off). Rather than re-issuing an
//    ALTER TABLE ... ENUM(...) migration every time a new lifecycle
//    state is needed, this converts the column to a plain string;
//    App\Models\Fixture::STATUSES is now the single source of truth for
//    valid values, enforced in the application layer exactly like every
//    other admin-only field on this table already is. Laravel 12's
//    schema builder alters this natively on both MySQL and SQLite
//    without requiring doctrine/dbal.
//
// 2) `provider_status` stores the raw football-data.org status string
//    (SCHEDULED, TIMED, IN_PLAY, PAUSED, FINISHED, POSTPONED, SUSPENDED,
//    CANCELLED, AWARDED) for the fixtures that came from the API — kept
//    verbatim alongside our own mapped `status` so an admin can always
//    see exactly what the provider last reported, even when our mapping
//    collapses a couple of provider states into one local status.
//    Always null for manual fixtures.
//
// 3) `last_synced_at` records the last time FixtureSyncService updated
//    this specific fixture from the provider — shown in the admin UI so
//    it's obvious how fresh a given fixture's status/score is.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixtures', function (Blueprint $table) {
            $table->string('status', 20)->default('scheduled')->change();
        });

        Schema::table('fixtures', function (Blueprint $table) {
            $table->string('provider_status', 30)->nullable()->after('external_id');
            $table->timestamp('last_synced_at')->nullable()->after('provider_status');
        });
    }

    public function down(): void
    {
        Schema::table('fixtures', function (Blueprint $table) {
            $table->dropColumn(['provider_status', 'last_synced_at']);
        });

        Schema::table('fixtures', function (Blueprint $table) {
            $table->enum('status', ['scheduled', 'live', 'finished', 'cancelled'])
                ->default('scheduled')->change();
        });
    }
};
