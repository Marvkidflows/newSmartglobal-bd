<?php
// LOCATION: database/migrations/2026_08_22_100003_create_investor_task_logs_table.php
//
// Auditable activity trail for investor_tasks (Guide §16 "activity history
// should be auditable"). Mirrors investment_countdown_logs in shape/intent.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_task_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_task_id')->constrained()->onDelete('cascade');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete(); // admin or investor who triggered it
            $table->string('actor_type')->default('admin'); // 'admin' | 'investor' | 'system'
            $table->string('action'); // created, assigned, notified, activated, code_submitted, extended, reduced,
                                       // window_set, verified, completed, closed, expired, cancelled, failed
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->json('meta')->nullable(); // arbitrary context: days_changed, reason, amounts, etc.
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_task_logs');
    }
};
