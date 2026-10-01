<?php
// LOCATION: database/migrations/2026_09_20_113101_create_staff_messages_table.php
//
// A single shared channel between Admin and Financial — not one thread
// per pair of users. Any admin can post, any financial-role user can
// post, and everyone on both sides sees the same conversation, the same
// way a team chat channel works rather than 1:1 DMs. This fits the
// actual shape of the need: Admin already has full technical access to
// everything Financial does (FinancialMiddleware admits admin on every
// route already) — what was missing wasn't access, it was a place for
// the two teams to actually talk to each other.
//
// Deliberately NOT built on the existing `messages` table: that table
// requires an investor_id on every row (it's structurally an
// Investor<->Admin support conversation) and has no way to represent
// "this message has no investor attached at all." A new table was
// simpler and clearer than bending that one to fit a different shape.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sender_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            $table->text('body');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_messages');
    }
};
