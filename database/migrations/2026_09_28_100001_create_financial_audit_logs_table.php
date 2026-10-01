<?php
// LOCATION: database/migrations/2026_09_28_100001_create_financial_audit_logs_table.php
//
// Financial Team audit trail. Inspection found NO general audit log in the
// project — only domain-specific logs (investment_countdown_logs,
// investor_task_logs, team_request_activity) plus balance_adjustments.
// balance_adjustments is kept as-is (it is the wallet ledger the investor
// and admin screens already read); this table is the cross-entity,
// append-only trail for every sensitive Financial Team action.
//
// Append-only by design: the model blocks update/delete, and no route
// exposes any write path to it.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();          // FAL-XXXXXXXX
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');                        // snapshot, survives user deletion
            $table->string('actor_role', 30);                    // financial | admin
            $table->string('department', 30)->default('financial');
            $table->string('action', 60)->index();               // e.g. investment.amount_corrected
            $table->string('entity_type', 60);                   // investment_account, deposit, ...
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('investor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('previous_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['investor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_audit_logs');
    }
};
