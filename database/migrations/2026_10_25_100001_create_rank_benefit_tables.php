<?php
// Investor Rank BENEFITS & PRIVILEGES framework (SSI Investor Rank, Benefits and Privileges Framework, §3–§8).
//
// ADDITIVE ONLY: creates new tables. No existing table is altered, no investor record, rank, criterion, balance
// or investment is read or written. The catalogue is populated afterwards by RankBenefitService::syncDefaults(),
// which only INSERTS rows that are missing (matched by `key`) and never overwrites anything Management has edited,
// so running this migration — or `php artisan ranks:sync-benefits` — any number of times is safe.
//
// Nothing here changes a contractual return. Financial incentives are stored as INACTIVE records; see
// RankIncentiveService for the approval chain that must be completed before one can be switched on.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Benefit catalogue (one row per benefit per rank) ───────────────────────────────────────────────
        Schema::create('rank_benefits', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();                       // e.g. gold.support-escalation
            $table->foreignId('investor_rank_id')->constrained('investor_ranks');
            $table->string('category', 20);                            // recognition|service|reinvestment|loyalty|loan|roi
            $table->string('title', 160);
            $table->text('description');
            // available + active | subject to eligibility | pending approval | suspended or unavailable
            $table->string('status', 30)->default('pending_approval');
            // platform = delivered by the application; operational = delivered by SSI staff; future_feature = platform feature not built yet
            $table->string('fulfilment', 20)->default('operational');
            $table->boolean('is_financial')->default(false);           // can never be switched on through the benefit screen
            $table->boolean('requires_approval')->default(true);       // switching on needs a written approval reference
            $table->boolean('requires_consent')->default(false);       // only applies once the investor has consented
            $table->boolean('cumulative')->default(true);              // still applies at higher ranks (false for the rank badge)
            $table->boolean('is_published')->default(true);            // shown to investors
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approved_by_name', 120)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_reference', 160)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['investor_rank_id', 'display_order']);
        });

        // ── Append-only audit trail for benefits, incentive terms and the lending programme ────────────────
        Schema::create('rank_benefit_audit', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 20);                        // benefit|incentive|loan_programme
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('action', 40);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 120);
            $table->string('actor_role', 30);
            $table->json('previous')->nullable();
            $table->json('new')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
        });

        // ── Rank-based incentive TERMS (the configurable benefits engine). Empty by default: no value is ever seeded. ──
        Schema::create('rank_incentive_terms', function (Blueprint $table) {
            $table->id();
            $table->string('label', 160);
            $table->foreignId('investor_rank_id')->constrained('investor_ranks');
            $table->unsignedBigInteger('investment_plan_id')->nullable();   // null = not plan-specific
            $table->string('kind', 20);                                      // fixed_bonus|percentage_bonus
            $table->decimal('value', 12, 4)->nullable();                     // entered by Management only
            $table->text('conditions')->nullable();
            // draft → pending_approval → approved → active ; disabled at any time
            $table->string('status', 20)->default('draft');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approved_by_name', 120)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_reference', 160)->nullable();
            $table->unsignedBigInteger('activated_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['investor_rank_id', 'status']);
        });

        // ── Investor consent (e.g. optional recognition in private investor communications). Append-only log. ──
        Schema::create('investor_consent_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('purpose', 40);                                   // recognition_communications
            $table->boolean('granted');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'purpose', 'id']);
        });

        // ── Lending programme switchboard (single row). Authorised/active = false until Management says otherwise. ──
        Schema::create('loan_programme_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_authorized')->default(false);                // formally approved (legal / policy)
            $table->boolean('is_active')->default(false);                    // open to applications
            $table->string('policy_reference', 160)->nullable();             // reference of the approved lending policy
            $table->json('eligible_rank_keys')->nullable();                  // default ["diamond","elite"]
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        // ── Loan applications (eligibility pathway + tracking only; approval is always a separate human decision) ──
        Schema::create('loan_applications', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('rank_id_at_application')->constrained('investor_ranks');
            $table->decimal('requested_amount', 15, 2);
            $table->text('purpose')->nullable();
            $table->string('status', 20)->default('submitted');              // submitted|under_review|approved|rejected|withdrawn
            $table->text('approved_terms')->nullable();                      // written by the decision-maker; the system invents none
            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->string('decided_by_name', 120)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('loan_application_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_application_id')->constrained('loan_applications');
            $table->string('event', 30);                                     // submitted|under_review|approved|rejected|withdrawn
            $table->string('actor_name', 120);
            $table->string('actor_role', 30);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Populate the catalogue (INSERT-ONLY, idempotent). rescue(): a seeding problem must never fail a deploy.
        if (class_exists(\App\Services\Rank\RankBenefitService::class)) {
            rescue(fn () => app(\App\Services\Rank\RankBenefitService::class)->syncDefaults(), null, true);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_application_events');
        Schema::dropIfExists('loan_applications');
        Schema::dropIfExists('loan_programme_settings');
        Schema::dropIfExists('investor_consent_log');
        Schema::dropIfExists('rank_incentive_terms');
        Schema::dropIfExists('rank_benefit_audit');
        Schema::dropIfExists('rank_benefits');
    }
};
