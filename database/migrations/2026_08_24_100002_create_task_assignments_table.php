<?php
// LOCATION: database/migrations/2026_08_24_100002_create_task_assignments_table.php
//
// One row per (task, investor) pair. This is where all per-investor
// tracking lives: whether they accessed the task, their own status,
// their submission, their result — fully independent from every other
// investor sharing the same task_code.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('task_id')->constrained('shared_tasks')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // Per-investor lifecycle — same state set as before, just scoped
            // to one investor's own journey through the shared task now.
            // pending | awaiting_activation | active | in_progress |
            // submitted | under_review | completed | expired | cancelled | failed
            $table->string('status')->default('awaiting_activation');

            $table->timestamp('activated_at')->nullable(); // when THIS investor entered the code

            $table->decimal('submitted_amount', 15, 2)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->text('submitted_notes')->nullable();

            $table->decimal('amount_used', 15, 2)->nullable();
            $table->decimal('amount_received', 15, 2)->nullable();
            $table->decimal('profit_loss', 15, 2)->nullable();
            $table->text('result_notes')->nullable();
            $table->string('final_result')->nullable();

            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // An investor can only be assigned once to a given task.
            $table->unique(['task_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignments');
    }
};
