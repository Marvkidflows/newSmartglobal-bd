<?php
// Direct messages from the MarvFlow team dashboard to the SSI Admin / SSI Financial Team inboxes.
// PURELY ADDITIVE: one new table. Nothing existing (staff_messages, team_requests, messages) is changed.
//
// A message is addressed to a ROLE (admin | financial), never to a user id supplied by the client. It is visible to
// every active user holding that role (the same shared-inbox behaviour as the existing Admin<->Financial channel).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marvflow_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
            $table->string('recipient_role', 20);                 // 'admin' | 'financial'
            $table->string('subject', 150);                       // plain text
            $table->mediumText('body_html');                      // SANITISED rich text (allow-list), see RichTextSanitizer
            $table->text('body_text');                            // plain-text rendering, for previews/notifications
            $table->string('client_token', 64);                   // idempotency: a retry of the same compose never duplicates
            $table->string('status', 12)->default('sent');        // sent = saved & in the SSI inbox | read = opened by a recipient-role user
            $table->unsignedInteger('notified_count')->default(0); // bell notifications created (informational only)
            $table->timestamp('read_at')->nullable();
            $table->foreignId('read_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sender_id', 'client_token']);
            $table->index(['recipient_role', 'created_at']);
            $table->index(['sender_id', 'created_at']);
        });
    }

    public function down(): void
    {
        // Drops only this table. No other table or record is touched.
        Schema::dropIfExists('marvflow_messages');
    }
};
