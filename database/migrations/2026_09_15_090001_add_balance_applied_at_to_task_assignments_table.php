<?php
// LOCATION: database/migrations/2026_09_15_090001_add_balance_applied_at_to_task_assignments_table.php
//
// The task result flow (verify() records amount_used/amount_received/
// profit_loss, complete() finalizes the assignment) never actually
// touched the investor's wallet balance — profit_loss sat on the
// assignment row as a number nobody applied anywhere. This column marks
// the exact moment AdminTaskController::complete() applied that
// profit_loss to the user's balance (via BalanceAdjustment, same as
// deposits/withdrawals), so the credit/debit can only ever happen once
// per assignment even if complete() were somehow invoked again.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_assignments', function (Blueprint $table) {
            $table->timestamp('balance_applied_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('task_assignments', function (Blueprint $table) {
            $table->dropColumn('balance_applied_at');
        });
    }
};
