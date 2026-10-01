<?php
// LOCATION: database/migrations/2026_09_19_132708_create_team_requests_table.php
//
// MarvFlow Team Dashboard — the core ticket table. One row per request
// a Smart System Investment admin/financial user submits to the
// MarvFlow development team.
//
// Deliberately a NEW table rather than extending the existing
// `messages` table (Investor<->Admin support chat): that table has no
// concept of priority, status, assignment, or a subject line, and its
// investor_id-is-always-set design doesn't fit an admin/financial-team
// initiated ticket at all. Bolting ticket fields onto a table built for
// a different, simpler purpose would have made both harder to reason
// about — see the final report for the full inspection notes.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_requests', function (Blueprint $table) {
            $table->id();

            // Who submitted it — an admin or financial-role SSI user.
            // Requests are only ever visible to their own sender on the
            // SSI side (DevRequestController scopes every query to
            // sender_id = auth user), so this is also the access-control
            // anchor, not just a label.
            $table->foreignId('sender_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            $table->string('subject');
            $table->text('message');

            $table->enum('priority', ['normal', 'high', 'urgent'])->default('normal');
            $table->enum('status', ['new', 'acknowledged', 'in_progress', 'waiting_for_info', 'resolved', 'closed'])
                  ->default('new');

            // Single attachment on the initial request (a screenshot of
            // the bug, typically) — stored the same way KYC documents
            // already are (Cloudinary secure_url). Replies can carry
            // their own attachment too (see team_request_messages).
            $table->string('attachment_url')->nullable();

            // Who on the MarvFlow team is handling this. Nullable = a
            // "New" request nobody has picked up yet. A single column
            // rather than a separate assignments table: the spec calls
            // for one current assignee, and every reassignment is
            // already captured in team_request_activity below, so a
            // dedicated history table here would just duplicate that.
            $table->foreignId('assigned_to')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');

            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['status']);
            $table->index(['priority']);
            $table->index(['assigned_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_requests');
    }
};
