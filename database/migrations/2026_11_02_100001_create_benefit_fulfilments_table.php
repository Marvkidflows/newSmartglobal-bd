<?php
// LOCATION: database/migrations/2026_11_02_100001_create_benefit_fulfilments_table.php
//
// Phase D (OPTIONAL patch 05): an auditable work-queue for benefits that SSI STAFF deliver (priority support, briefings, education,
// relationship management, premium services). ADDITIVE ONLY: one new table; no existing table, balance, rank or financial record is read
// or written. A row records a REQUEST and its fulfilment; enabling a catalogue entry never creates a row and never counts as delivery.
// STATUS: NOT EXECUTED by its author (no PHP available). Apply only after the Phase B patches have been verified locally.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_fulfilments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('rank_benefit_id')->constrained('rank_benefits');
            $table->string('benefit_key', 80);                          // copy of rank_benefits.key at request time
            // requested -> assigned -> in_progress -> completed ; or declined / cancelled from any open state
            $table->string('status', 20)->default('requested');
            $table->unsignedBigInteger('assigned_to')->nullable();      // the responsible SSI team member
            $table->string('assigned_to_name', 120)->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('completion_reference', 160)->nullable();    // evidence / completion reference; REQUIRED to complete
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'rank_benefit_id', 'status']);
            $table->index(['status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_fulfilments');
    }
};
