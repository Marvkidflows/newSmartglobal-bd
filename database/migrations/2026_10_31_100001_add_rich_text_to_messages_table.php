<?php
// Formatted (rich-text) messages for the Admin ↔ Investor and Financial ↔ Investor channels (they share the `messages` table).
// ADDITIVE and backward compatible: ONE nullable column. `body` stays the plain-text version of every message (previews, search,
// notifications and old clients keep reading it); `body_html` holds the sanitised formatted version, or NULL for plain/legacy messages.
// No existing row is changed, nothing is backfilled, no index or constraint is added.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // no ->after(): a column appended at the END can be added instantly (MySQL >= 8.0.12 / MariaDB >= 10.3) instead of rebuilding the table
            $table->mediumText('body_html')->nullable();
        });
    }

    /** Lossless for text: only the formatting of messages written after the upgrade is lost (their plain `body` is kept). */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('body_html');
        });
    }
};
