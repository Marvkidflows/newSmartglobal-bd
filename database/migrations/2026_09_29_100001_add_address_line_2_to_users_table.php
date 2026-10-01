<?php
// LOCATION: database/migrations/2026_09_29_100001_add_address_line_2_to_users_table.php
//
// Investor Mailing / Postal Information — additive field only.
//
// Inspection of the existing schema found the structured address fields
// the brief asks for ALREADY EXIST on `users` and are already collected at
// registration (Stage 2 KYC) and already validated on the investor profile
// endpoint:
//
//   residential_address (text)  -> reused as "Address Line 1"
//   city                (string)-> reused as "City"
//   state                (string)-> reused as "State / Province"
//   postal_code          (string)-> reused as "Postal / ZIP Code"
//   country / country_iso2       -> reused as "Country" (derived from phone)
//
// The only field genuinely missing is an optional second address line, so
// that is the only new column this migration adds. See
// FINANCIAL_TEAM_CORRECTION_REPORT.md for the full list of fields reused
// vs. added, and for a second, unrelated bug this work uncovered and fixed
// in the same area (residential_address was missing from User::$fillable,
// so it was being silently discarded at registration — see the fix in
// app/Models/User.php in this same change).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'address_line_2')) {
                $table->string('address_line_2')->nullable()->after('residential_address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'address_line_2')) {
                $table->dropColumn('address_line_2');
            }
        });
    }
};
