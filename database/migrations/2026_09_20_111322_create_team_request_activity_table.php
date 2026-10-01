<?php
// LOCATION: database/migrations/2026_09_19_132710_create_team_request_activity_table.php
//
// MarvFlow Team Dashboard — audit trail for a team_request: every
// status change, priority change, and assignment change gets one row
// here, so the "Activity/history" section on the request detail page
// has something real to show rather than just the current state.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_request_activity', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_request_id')
                  ->constrained('team_requests')
                  ->onDelete('cascade');

            // Nullable — a future system-generated entry (e.g. an
            // auto-close after N days of inactivity, if ever added)
            // wouldn't have a human actor.
            $table->foreignId('actor_id')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');

            // e.g. 'created', 'status_changed', 'priority_changed',
            // 'assigned', 'reassigned', 'unassigned'
            $table->string('action', 50);
            $table->string('from_value')->nullable();
            $table->string('to_value')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['team_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_request_activity');
    }
};
