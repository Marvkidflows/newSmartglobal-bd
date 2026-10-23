<?php
// Versioned, attributable record of Management's currency-policy confirmation for the rank engine.
//
// WHY: money-based rank criteria (total invested, active investment, approved deposits) may only go live once
// SSI Management has confirmed how amounts are converted to the USD ranking currency. That confirmation used to
// be an environment flag (RANKS_CURRENCY_CONFIRMED) that required editing .env — unattributed and unaudited.
//
// Each row is one VERSION of the policy: the exact rules shown to the reviewer (rules + rules_hash), who reviewed
// it (Financial Team / Admin), who confirmed it (Admin) and when, and how Management chose to treat records that
// still lack a verified USD value. Rows are never deleted; only the status/confirmation/revocation columns change.
// This migration is ADDITIVE: it creates one table and touches no investor, investment, deposit or balance data.
// It does NOT create or approve any policy — until a person confirms one, money criteria stay locked.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_currency_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->string('status', 24)->index();          // pending_confirmation | confirmed | revoked | superseded
            $table->string('ranking_currency', 8);
            $table->json('rules');                            // the exact rule set that was reviewed / confirmed
            $table->string('rules_hash', 64)->index();        // sha256 of the rules; a mismatch later means "changed since approval"

            // Step 1 — review (Financial Team or Admin)
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('reviewed_by_name', 120)->nullable();
            $table->string('reviewed_by_role', 30)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->json('review_snapshot')->nullable();      // record counts that needed review at that moment

            // Step 2 — confirmation (Admin, on behalf of Management)
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->string('confirmed_by_name', 120)->nullable();
            $table->string('confirmed_by_role', 30)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('confirmation_note')->nullable();
            $table->string('unverified_handling', 24)->nullable();      // accept_exclusions | require_clear
            $table->unsignedInteger('accepted_review_count')->nullable(); // records Management knowingly accepted as excluded

            // Withdrawal of the confirmation
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->string('revoked_by_name', 120)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_currency_policies');
    }
};
