<?php
// LOCATION: database/migrations/2026_09_19_132707_add_marvflow_roles_to_users_table.php
//
// MarvFlow Team Dashboard — adds two new values to the existing
// users.role enum (currently: admin, investor, financial). Raw SQL is
// required here because MySQL enums can't be extended through the
// normal Schema::table()->enum() builder without dropping/recreating
// the column; this ALTER preserves every existing row and its current
// role untouched — it only widens the set of values the column accepts.
//
// marvflow_member — can view requests, reply, update status, add notes,
//                   assign a request to themselves.
// marvflow_lead    — everything a member can do, plus: view all team
//                   requests+activity, reassign requests to other
//                   members, change priority.
//
// These two are intentionally NOT treated as admin-equivalent anywhere
// (see MarvflowMiddleware) — MarvFlow is Smart System Investment's
// external development team, not part of its internal staff hierarchy.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'investor', 'financial', 'marvflow_member', 'marvflow_lead') NOT NULL DEFAULT 'investor'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'investor', 'financial') NOT NULL DEFAULT 'investor'");
    }
};
