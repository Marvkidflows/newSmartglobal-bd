<?php
// LOCATION: database/migrations/2026_09_01_100001_add_country_iso2_and_phone_unique_to_users_table.php
//
// Part of the Phase 2 phone/country validation fix.
// - country_iso2 stores the 2-letter region code (e.g. "NG", "US") the
//   investor actually selected on the frontend. `country_code` (dial code,
//   e.g. "+234") and `country` (display name, e.g. "Nigeria") are still
//   kept, but are now derived server-side FROM this ISO2 code rather than
//   trusted as free text from the client — see RegisterController.
// - phone is now stored normalized (E.164, e.g. "+2348012345678") and
//   given a unique index so duplicate accounts can't be created with the
//   same real-world phone number in different formats.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('country_iso2', 2)->nullable()->after('country_code');
        });

        // Existing rows may already contain duplicate/garbage phone values
        // (this is exactly the bug being fixed) — null them out rather than
        // fail the migration on a unique-index collision. Affected users
        // simply re-enter a valid phone number next time they touch their
        // profile; nothing else about their account is affected.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("
                UPDATE users u
                JOIN (
                    SELECT phone FROM users
                    WHERE phone IS NOT NULL AND phone != ''
                    GROUP BY phone HAVING COUNT(*) > 1
                ) dupes ON u.phone = dupes.phone
                SET u.phone = NULL
            ");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn('country_iso2');
        });
    }
};
