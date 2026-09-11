<?php
// LOCATION: database/migrations/2026_09_06_100001_add_source_and_publish_state_to_fixtures_table.php
//
// Extends the EXISTING fixtures table (not a new/duplicate fixture
// system) to support two sources per the final fixture-source-flow
// requirement:
//   - source:        'manual' (admin typed it in — existing, unchanged
//                     behavior) or 'api' (fetched from football-data.org)
//   - is_published:   whether investors can see it. Manual fixtures stay
//                     published=true immediately, exactly as they already
//                     work today. API-fetched fixtures land as
//                     published=false — reviewed and explicitly
//                     published by an admin before any investor sees
//                     them. This is the "do not automatically expose
//                     every API fixture to users" requirement, enforced
//                     at the schema level, not just in a controller.
//   - external_id:    the football-data.org match ID, used to avoid
//                     re-importing the same fixture on a repeat fetch.
//                     Null for manual fixtures.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixtures', function (Blueprint $table) {
            $table->enum('source', ['manual', 'api'])->default('manual')->after('away_score');
            $table->boolean('is_published')->default(true)->after('source');
            $table->string('external_id', 40)->nullable()->unique()->after('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('fixtures', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn(['source', 'is_published', 'external_id']);
        });
    }
};
