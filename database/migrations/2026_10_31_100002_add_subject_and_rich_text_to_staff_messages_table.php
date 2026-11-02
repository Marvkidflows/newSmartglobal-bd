<?php
// Admin ↔ Financial staff channel: an optional subject and formatted (rich-text) messages.
// ADDITIVE and backward compatible: two nullable columns, no backfill, no index, no constraint. Existing rows are untouched
// (NULL subject / NULL body_html = a plain legacy message). `body` stays the plain-text version of every message.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_messages', function (Blueprint $table) {
            // no ->after(): appended at the END so MySQL can add them instantly rather than rebuilding the table
            $table->string('subject', 150)->nullable();
            $table->mediumText('body_html')->nullable();
        });
    }

    /**
     * SAFE ROLLBACK: refuses while any message has a subject — dropping the column would delete text people wrote.
     * Nothing is changed when it refuses; back up the table and decide (see APPLY.md). Formatting alone (body_html) is
     * lossless to drop because the plain `body` is always stored.
     */
    public function down(): void
    {
        $subjects = Schema::hasColumn('staff_messages', 'subject')
            ? DB::table('staff_messages')->whereNotNull('subject')->count()
            : 0;

        if ($subjects > 0) {
            throw new \RuntimeException("Rollback refused: {$subjects} staff message(s) have a subject. Back up staff_messages and read APPLY.md (rollback section) first. Nothing was changed.");
        }

        Schema::table('staff_messages', function (Blueprint $table) {
            $table->dropColumn(['subject', 'body_html']);
        });
    }
};
