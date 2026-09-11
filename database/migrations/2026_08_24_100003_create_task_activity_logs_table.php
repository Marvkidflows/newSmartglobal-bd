<?php
// LOCATION: database/migrations/2026_08_24_100003_create_task_activity_logs_table.php
//
// Replaces investor_task_logs for the new model. task_id is set for
// task-wide events (created, window extended — affects every assignee);
// task_assignment_id is set for per-investor events (code submitted,
// activity submitted, verified). At least one of the two is always set.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->nullable()->constrained('shared_tasks')->onDelete('cascade');
            $table->foreignId('task_assignment_id')->nullable()->constrained('task_assignments')->onDelete('cascade');

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type')->default('admin'); // admin | investor | system

            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->json('meta')->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_activity_logs');
    }
};
