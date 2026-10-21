<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Permanent, append-only record of every rank decision.
        // investor_id / actor_id carry no FK so the record survives any
        // later account change; account_id is also snapshotted as text.
        Schema::create('investor_rank_changes', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->unsignedBigInteger('investor_id')->index();
            $table->string('account_id', 24)->index();
            $table->unsignedInteger('sequence');                 // per-investor, strictly increasing
            $table->foreignId('previous_rank_id')->nullable()->constrained('investor_ranks');
            $table->foreignId('new_rank_id')->constrained('investor_ranks');
            // automatic_upgrade | manual_assignment | manual_upgrade | downgrade | authorized_override | review_retained
            $table->string('change_type', 30)->index();
            $table->boolean('is_automatic')->default(false)->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_name', 120);
            $table->string('actor_role', 20)->nullable();
            $table->text('reason');
            $table->text('override_justification')->nullable();
            $table->json('criteria_snapshot')->nullable();
            $table->unsignedBigInteger('evaluation_run_id')->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->unique(['investor_id', 'sequence']);          // blocks duplicate / conflicting transitions
        });

        // Delivery state for the admin alert, kept OUT of the immutable audit row.
        Schema::create('rank_change_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('investor_rank_change_id')->unique();
            $table->string('status', 12)->default('pending')->index(); // pending|sent|failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        // Investors who no longer satisfy their CURRENT rank's active criteria.
        // Never auto-actioned; waits for an Admin decision.
        Schema::create('rank_downgrade_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('investor_id')->index();
            $table->foreignId('investor_rank_id')->constrained('investor_ranks');
            $table->unsignedBigInteger('rank_criterion_id');
            $table->unsignedInteger('criterion_version');
            $table->string('fingerprint', 40);
            $table->json('failed_conditions');
            $table->string('status', 12)->default('open')->index();    // open|retained|downgraded|cleared
            $table->timestamp('notified_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->string('resolved_by_name', 120)->nullable();
            $table->text('resolution_reason')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            // Same unchanged shortfall can never raise a second alert/review.
            $table->unique(['investor_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_downgrade_reviews');
        Schema::dropIfExists('rank_change_notifications');
        Schema::dropIfExists('investor_rank_changes');
    }
};
