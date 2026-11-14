<?php
// LOCATION: database/migrations/2026_11_04_100001_harden_financial_approval_workflow.php
//
// ADDITIVE ONLY (R7 / R8). Adds NULLABLE columns to financial_approval_requests and creates the append-only financial_approval_events table.
// It does not read, change or delete any existing row, so it cannot alter any balance or investment. It is a NEW migration (not an edit of
// 2026_11_03_100001) so a database that already ran the earlier one upgrades cleanly. Run ONLY through the explicit "migrate" deployment role.
// Reversible via down().

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_approval_requests', function (Blueprint $t) {
            // pending | executing | executed | rejected | failed | cancelled | expired   (legacy rows may also hold 'approved')
            $t->unsignedBigInteger('target_user_id')->nullable()->index();       // the investor/account the request concerns (display + filtering)
            $t->decimal('amount_usd', 15, 2)->nullable();                         // monetary size used for cumulative-threshold checks
            $t->string('authorization_reference', 255)->nullable();
            $t->string('open_key', 160)->nullable()->unique();                    // set while pending/failed; NULL once terminal → at most ONE open request per payload
            $t->timestamp('expires_at')->nullable()->index();
            $t->unsignedBigInteger('cancelled_by')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->timestamp('execution_started_at')->nullable();
            $t->unsignedSmallInteger('execution_attempts')->default(0);
            $t->text('last_error')->nullable();
        });

        Schema::create('financial_approval_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('approval_request_id')->index();
            $t->string('event', 30);                          // submitted | approved | rejected | execution_started | executed | failed | retried | cancelled | expired | recovered
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_role', 30)->nullable();
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20)->nullable();
            $t->text('note')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_approval_events');
        Schema::table('financial_approval_requests', function (Blueprint $t) {
            $t->dropUnique(['open_key']);
            $t->dropIndex(['target_user_id']);
            $t->dropIndex(['expires_at']);
            $t->dropColumn(['target_user_id', 'amount_usd', 'authorization_reference', 'open_key', 'expires_at', 'cancelled_by', 'cancelled_at',
                            'cancel_reason', 'execution_started_at', 'execution_attempts', 'last_error']);
        });
    }
};
