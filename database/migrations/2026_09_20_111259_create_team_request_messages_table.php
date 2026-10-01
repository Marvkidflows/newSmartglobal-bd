<?php
// LOCATION: database/migrations/2026_09_19_132709_create_team_request_messages_table.php
//
// MarvFlow Team Dashboard — the two-way conversation thread attached to
// a team_request. Both the original SSI sender and any MarvFlow team
// member can post here once the request exists.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_request_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_request_id')
                  ->constrained('team_requests')
                  ->onDelete('cascade');

            $table->foreignId('sender_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            // Denormalized rather than derived from sender's role at
            // render time — lets the frontend place a bubble left/right
            // (same pattern as messages.initiated_by) without an extra
            // join or trusting a role that could theoretically change
            // after the message was sent.
            $table->enum('sender_side', ['ssi', 'marvflow']);

            $table->text('body');
            $table->string('attachment_url')->nullable();

            $table->timestamps();

            $table->index(['team_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_request_messages');
    }
};
