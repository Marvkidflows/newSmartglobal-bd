<?php
// LOCATION: database/migrations/2026_11_06_100001_create_rank_progress_notices_table.php
// Additive and safe: one new table, no change to any existing table or record. Remembers which "approaching the next rank" milestones an
// investor has already been told about, so the daily sweep can never repeat a notice, yet can send a fresh one after progress genuinely
// fell back (the row is "re-armed" by setting `active` to NULL, which the unique index ignores).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_progress_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('next_rank_id')->constrained('investor_ranks')->cascadeOnDelete();
            $table->unsignedTinyInteger('milestone');
            $table->unsignedTinyInteger('percent_at_notice');
            $table->unsignedTinyInteger('amount_percent')->nullable();
            $table->unsignedTinyInteger('cycles_percent')->nullable();
            $table->boolean('active')->nullable()->default(true);     // true while sent and still armed; NULL once re-armed
            $table->boolean('carried_notification')->default(false);   // a batch of milestones sends ONE notice, carried by the highest
            $table->char('notification_id', 36)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('reset_at')->nullable();
            $table->timestamps();

            $table->unique(['investor_id', 'next_rank_id', 'milestone', 'active'], 'rpn_active_unique');
            $table->index(['investor_id', 'next_rank_id'], 'rpn_investor_rank_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_progress_notices');
    }
};
