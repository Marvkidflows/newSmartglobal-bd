<?php
// LOCATION: database/migrations/2026_10_23_100001_add_privacy_acknowledgement_to_users.php
//
// Records WHICH version of the Data Privacy Policy a user acknowledged and
// WHEN. Deliberately nothing else (no IP address, no user agent) — the
// policy says to avoid collecting extra personal data just to record consent.
//
// Both columns are nullable: existing users have not acknowledged any
// version, and this migration does not pretend otherwise. The pre-existing
// `terms_accepted` column is left untouched and is not reused.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'privacy_policy_version')) {
                $table->string('privacy_policy_version', 64)->nullable();
            }
            if (!Schema::hasColumn('users', 'privacy_policy_acknowledged_at')) {
                $table->timestamp('privacy_policy_acknowledged_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['privacy_policy_version', 'privacy_policy_acknowledged_at'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
