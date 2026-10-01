<?php
// LOCATION: database/migrations/2026_09_28_100002_add_department_and_notice_fields_to_messages_and_announcements.php
//
// Extends the EXISTING messages table (one thread per investor, staff side
// stored as initiated_by='admin') rather than creating a parallel mailbox.
//
//   department        which team the staff side of the row belongs to:
//                     'admin' (default — every existing row) or 'financial'.
//                     For investor-initiated rows it is the team the
//                     investor addressed.
//   sender_label      display identity, e.g. "Smart System Investment —
//                     Financial Team". Stored so history never changes
//                     if a staff member is renamed or removed.
//   kind              'message' (default) | 'notice' (official notice)
//   broadcast_id      groups the per-recipient rows of one multi-recipient
//                     send, so the sender can see one record of it.
//   read_by_financial staff-side read flag for the Financial inbox,
//                     independent of read_by_admin.
//
// initiated_by stays 'admin'|'investor' so every existing query and the
// investor unread logic keep working untouched.
//
// announcements: adds a nullable `department` so a Financial Team Notice
// published to the News Centre is identifiable; category 'financial_notice'
// is added in the model's CATEGORIES list (plain string column, no
// migration needed for that).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'department')) {
                $table->string('department', 30)->default('admin')->after('initiated_by');
            }
            if (!Schema::hasColumn('messages', 'sender_label')) {
                $table->string('sender_label')->nullable()->after('department');
            }
            if (!Schema::hasColumn('messages', 'kind')) {
                $table->string('kind', 20)->default('message')->after('sender_label');
            }
            if (!Schema::hasColumn('messages', 'broadcast_id')) {
                $table->string('broadcast_id', 40)->nullable()->after('kind')->index();
            }
            if (!Schema::hasColumn('messages', 'read_by_financial')) {
                $table->boolean('read_by_financial')->default(false)->after('read_by_investor');
            }
        });

        Schema::table('announcements', function (Blueprint $table) {
            if (!Schema::hasColumn('announcements', 'department')) {
                $table->string('department', 30)->nullable()->after('category');
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            foreach (['department', 'sender_label', 'kind', 'broadcast_id', 'read_by_financial'] as $col) {
                if (Schema::hasColumn('messages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('announcements', function (Blueprint $table) {
            if (Schema::hasColumn('announcements', 'department')) {
                $table->dropColumn('department');
            }
        });
    }
};
