<?php
// LOCATION: database/migrations/2026_11_19_100001_add_deposit_agent_routing.php
//
// Deposit -> Telegram connection (Stage 1). Purely additive: every column is nullable, nothing existing is changed or backfilled.
//  users.assigned_agent_id   the Financial Team member who looks after this investor (NULL = no individual assignment;
//                            the existing deployment-wide representative from TELEGRAM_AGENT_USERNAME then applies).
//  users.telegram_username   a staff member's Telegram handle (no "@"); only ever read for the investor's ASSIGNED agent.
//  users.telegram_chat_id    optional: lets the bot DM that agent. Without it the bot cannot DM them (Telegram forbids it).
//  deposits.idempotency_key  client-generated key for one submit attempt; UNIQUE per investor so a retry / double click
//                            returns the SAME deposit instead of creating a second one.
//  deposits.agent_notified_at set ONLY when the Telegram API confirmed delivery of the agent notification.
// No foreign key on assigned_agent_id on purpose (portable across MySQL / the SQLite test database); it is validated in code.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'assigned_agent_id'))  $table->unsignedBigInteger('assigned_agent_id')->nullable()->index();
            if (!Schema::hasColumn('users', 'telegram_username'))  $table->string('telegram_username', 64)->nullable();
            if (!Schema::hasColumn('users', 'telegram_chat_id'))   $table->string('telegram_chat_id', 64)->nullable();
        });

        Schema::table('deposits', function (Blueprint $table) {
            if (!Schema::hasColumn('deposits', 'idempotency_key'))   $table->string('idempotency_key', 64)->nullable();
            if (!Schema::hasColumn('deposits', 'agent_notified_at')) $table->timestamp('agent_notified_at')->nullable();
        });

        if (!Schema::hasIndex('deposits', 'deposits_user_idempotency_unique')) {
            Schema::table('deposits', function (Blueprint $table) {
                // NULL keys (every existing deposit, old clients) never collide.
                $table->unique(['user_id', 'idempotency_key'], 'deposits_user_idempotency_unique');
            });
        }
    }

    public function down(): void
    {
        // Intentionally not reversed: dropping these would discard assignments and idempotency evidence.
    }
};
