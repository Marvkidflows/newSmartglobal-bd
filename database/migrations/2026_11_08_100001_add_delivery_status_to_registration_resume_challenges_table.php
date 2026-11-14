<?php
// LOCATION: database/migrations/2026_11_08_100001_add_delivery_status_to_registration_resume_challenges_table.php
//
// Task 2 (security correction, round 2). A resume code may only be USED once we know it was handed to the mail provider, and a code whose
// delivery failed — or is ambiguous — must never be usable, while STILL counting toward the per-account cooldown and hourly cap (so a failing or
// flaky mail provider cannot be turned into unlimited code requests). The row is therefore kept, never deleted, and carries its delivery state:
//
//   pending  — created, not yet confirmed sent   (NOT usable)
//   sent     — handed to the mail provider        (usable until expiry / attempt cap / single use)
//   failed   — send threw, or the outcome is unclear (NOT usable; consumed at the same moment)
//
// Default 'pending' is deliberately fail-closed: any row that predates this column is unusable rather than silently trusted.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_resume_challenges', function (Blueprint $table) {
            $table->string('delivery_status', 16)->default('pending')->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('registration_resume_challenges', function (Blueprint $table) {
            $table->dropColumn('delivery_status');
        });
    }
};
