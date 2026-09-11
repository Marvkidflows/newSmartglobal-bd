<?php
// LOCATION: database/migrations/2026_08_22_100002_create_investor_tasks_table.php
//
// Investor Task Management System — the task = investor assignment.
// One unique task_code maps to exactly one investor (Guide §1, §2).
// NOTE: intentionally NOT reusing the legacy `tasks` / `task_completions`
// tables — those are dead code (only referenced in routes/web.php.backup)
// and have the wrong shape (no code, no investor assignment, no countdown).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_tasks', function (Blueprint $table) {
            $table->id();

            // ── IDENTITY ──
            $table->string('task_code')->unique(); // e.g. SSIS6836 — DB-enforced uniqueness
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // assigned investor
            $table->foreignId('task_type_id')->constrained('task_types');

            // ── DEFINITION (Guide §2, §6) ──
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();
            $table->decimal('required_amount', 15, 2)->nullable();

            // ── LIFECYCLE STATUS (Guide §12) ──
            // pending | awaiting_activation | active | in_progress | submitted |
            // under_review | completed | expired | cancelled | failed
            $table->string('status')->default('awaiting_activation');

            // ── WINDOW / COUNTDOWN (Guide §5, §10, §11) ──
            // Mirrors InvestmentAccount's end_date + remaining tracking pattern.
            $table->timestamp('activates_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_window_update')->nullable();
            $table->foreignId('window_modified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('window_modified_reason')->nullable();

            // ── SUBMISSION (Guide §9) ──
            $table->decimal('submitted_amount', 15, 2)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->text('submitted_notes')->nullable();

            // ── RESULT (Guide §13) — admin/authorized-workflow entered only,
            // no auto-calculated formulas, per explicit instruction. ──
            $table->decimal('amount_used', 15, 2)->nullable();
            $table->decimal('amount_received', 15, 2)->nullable();
            $table->decimal('profit_loss', 15, 2)->nullable();
            $table->text('result_notes')->nullable();
            $table->string('final_result')->nullable(); // free-text/label, e.g. 'profit' | 'loss' | 'break_even' — admin-set

            // ── REVIEW / VERIFICATION (Guide §7, §16) ──
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // ── ADMIN AUDIT ──
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_tasks');
    }
};
