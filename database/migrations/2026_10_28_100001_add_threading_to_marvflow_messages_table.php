<?php
// Two-way conversations on the existing marvflow_messages table (no parallel messaging system).
// ADDITIVE: three nullable/defaulted columns + indexes. No existing row is rewritten:
//   thread_id  NULL  = "this row is the first message of its own conversation" (every existing row)
//   direction  'to_ssi' (MarvFlow -> SSI, the default and the meaning of every existing row) | 'to_marvflow' (SSI reply)
//   recipient_id     = the MarvFlow user who owns the conversation; set ONLY by the server on SSI replies
// No database-level foreign keys are added (keeps the change portable across MySQL/SQLite); integrity is enforced in the
// controllers. If a conversation owner's account is deleted, the existing cascade removes their first messages; any orphaned
// reply rows are never listed (inboxes list first messages only).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marvflow_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('thread_id')->nullable()->after('id');
            $table->string('direction', 12)->default('to_ssi')->after('recipient_role');
            $table->unsignedBigInteger('recipient_id')->nullable()->after('direction');

            $table->index(['thread_id', 'id'], 'mfm_thread_idx');
            $table->index(['recipient_id', 'direction', 'read_at'], 'mfm_owner_unread_idx');
            $table->index(['recipient_role', 'direction', 'read_at'], 'mfm_inbox_unread_idx');
        });
    }

    /**
     * SAFE ROLLBACK: refuses to run while SSI replies exist. Dropping `direction` would turn every SSI reply into a row that
     * looks like an inbound MarvFlow message (sent by an Admin/Financial user) in the SSI inbox. Nothing is changed when it
     * refuses. Back the table up, decide what to do with the replies (see APPLY.md), then run this migration's rollback by
     * its exact path — never by --step.
     * MarvFlow follow-ups (direction to_ssi, thread_id set) are genuine inbound messages and simply become standalone ones.
     */
    public function down(): void
    {
        $replies = Schema::hasColumn('marvflow_messages', 'direction')
            ? DB::table('marvflow_messages')->where('direction', 'to_marvflow')->count()
            : 0;

        if ($replies > 0) {
            throw new \RuntimeException("Rollback refused: {$replies} SSI reply row(s) exist in marvflow_messages. Back up the table and read APPLY.md (rollback section) first. Nothing was changed.");
        }

        Schema::table('marvflow_messages', function (Blueprint $table) {
            $table->dropIndex('mfm_inbox_unread_idx');
            $table->dropIndex('mfm_owner_unread_idx');
            $table->dropIndex('mfm_thread_idx');
        });
        Schema::table('marvflow_messages', function (Blueprint $table) {
            $table->dropColumn(['recipient_id', 'direction', 'thread_id']);
        });
    }
};
