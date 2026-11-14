<?php
// Task 5 - referral bonus management + withdrawal fee configuration.
//
// ADDITIVE ONLY. New tables, plus NULLABLE columns on `withdrawals`. No existing row is rewritten except that existing withdrawals are
// backfilled gross = amount, fee = 0, net = amount (a pure copy; `amount` itself is never changed and remains the figure debited at approval).
// Seeds ONE referral-policy row (USD 5.00, policy NOT approved, no criteria enabled) and ONE withdrawal-fee policy row (type 'none', inactive).
// Nothing here switches on a reward or a fee.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Versioned, append-only referral policy (amount + eligibility criteria). Latest effective row wins; older rows are history.
        Schema::create('referral_policies', function (Blueprint $t) {
            $t->id();
            $t->decimal('reward_amount', 15, 2);
            $t->decimal('previous_reward_amount', 15, 2)->nullable();
            $t->string('currency', 8)->default('USD');
            $t->json('criteria');                                   // which activation criteria are required (all off until Management decides)
            $t->boolean('policy_approved')->default(false);         // rewards can only be approved/credited while the CURRENT policy is approved
            $t->string('approval_reference', 120)->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('effective_from')->useCurrent();
            $t->text('reason');
            $t->unsignedBigInteger('changed_by')->nullable();
            $t->string('changed_by_name', 120)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index('effective_from');
        });

        // Funnel events: link visited -> registration started -> registration completed -> activated -> eligibility confirmed.
        Schema::create('referral_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('referrer_id')->nullable()->index();
            $t->unsignedBigInteger('referred_id')->nullable()->index();
            $t->string('referral_code', 40)->nullable()->index();
            $t->string('event', 32);
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['event', 'created_at']);
        });

        // The referral rewards ledger. ONE row per referred investor (unique), status moves pending -> approved -> credited, or rejected / reversed.
        // Rows are never deleted; every transition is also written to referral_reward_events and the Financial audit log.
        Schema::create('referral_rewards', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 40)->unique();                  // RR-XXXXXXXXXX
            $t->unsignedBigInteger('referrer_id')->index();
            $t->unsignedBigInteger('referred_id')->unique();        // a referred account can earn at most one reward
            $t->string('referral_code', 40)->nullable();
            $t->unsignedBigInteger('policy_id')->nullable();        // policy version in force when the reward was created
            $t->decimal('amount', 15, 2);                           // snapshot: later policy changes never alter it
            $t->string('currency', 8)->default('USD');
            $t->string('status', 12)->default('pending')->index();  // pending|approved|credited|reversed|rejected
            $t->string('eligibility', 24)->default('not_checked');  // not_checked|policy_not_approved|not_eligible|eligible
            $t->json('eligibility_detail')->nullable();
            $t->timestamp('eligibility_checked_at')->nullable();
            $t->timestamp('registration_started_at')->nullable();
            $t->timestamp('registration_completed_at')->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->boolean('suspicious')->default(false)->index();
            $t->json('flags')->nullable();
            $t->text('decision_reason')->nullable();                // approval note / rejection reason
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->string('decided_by_name', 120)->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('credited_at')->nullable();
            $t->unsignedBigInteger('credited_by')->nullable();
            $t->string('credit_reference', 60)->nullable()->unique();   // unique transaction reference; DB-unique = duplicate-credit guard
            $t->unsignedBigInteger('balance_adjustment_id')->nullable();
            $t->string('reversal_reference', 60)->nullable()->unique();
            $t->text('reversal_reason')->nullable();
            $t->unsignedBigInteger('reversed_by')->nullable();
            $t->timestamp('reversed_at')->nullable();
            $t->unsignedBigInteger('reversal_adjustment_id')->nullable();
            $t->timestamps();
            $t->index(['referrer_id', 'status']);
        });

        Schema::create('referral_reward_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('reward_id')->index();
            $t->string('event', 32);                                // created|eligibility_checked|approved|rejected|credited|reversed|flagged|unflagged
            $t->string('from_status', 12)->nullable();
            $t->string('to_status', 12)->nullable();
            $t->text('reason')->nullable();
            $t->json('detail')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name', 120)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        // Versioned, append-only withdrawal-fee policy.
        Schema::create('withdrawal_fee_policies', function (Blueprint $t) {
            $t->id();
            $t->string('fee_type', 12)->default('none');            // none|fixed|percentage|combined
            $t->decimal('fixed_amount', 15, 2)->nullable();
            $t->decimal('percentage', 7, 4)->nullable();            // e.g. 2.5000 = 2.5 %
            $t->decimal('min_fee', 15, 2)->nullable();
            $t->decimal('max_fee', 15, 2)->nullable();
            $t->string('currency', 8)->default('USD');
            $t->boolean('is_active')->default(false);
            $t->boolean('policy_approved')->default(false);         // a fee is charged only if active AND approved AND effective
            $t->string('approval_reference', 120)->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('effective_from')->useCurrent();
            $t->text('reason');
            $t->unsignedBigInteger('configured_by')->nullable();
            $t->string('configured_by_name', 120)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index('effective_from');
        });

        Schema::table('withdrawals', function (Blueprint $t) {
            $t->decimal('gross_amount', 15, 2)->nullable();
            $t->decimal('fee_amount', 15, 2)->nullable();
            $t->decimal('net_amount', 15, 2)->nullable();
            $t->string('fee_currency', 8)->nullable();
            $t->unsignedBigInteger('fee_policy_id')->nullable();
            $t->json('fee_calculation')->nullable();                // exact inputs + steps, frozen at submission
            $t->string('fee_status', 16)->nullable()->index();      // none|charged|collected|not_collected|reversed|waived|review
            $t->timestamp('fee_confirmed_at')->nullable();
            $t->string('transaction_reference', 40)->nullable()->unique();
            $t->string('idempotency_key', 64)->nullable()->unique();
        });

        // Append-only fee sub-ledger: one row per fee event, linked to the withdrawal (charged / collected / not_collected / reversed / waived / review).
        Schema::create('withdrawal_fee_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('withdrawal_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('event', 16);
            $t->decimal('amount', 15, 2);
            $t->string('currency', 8);
            $t->string('event_key', 120)->unique();                  // idempotency: the same event can never be written twice
            $t->string('reference', 60)->nullable();
            $t->unsignedBigInteger('related_event_id')->nullable(); // a reversal points at the collection it reverses
            $t->text('reason')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name', 120)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        // Backfill (pure copy; existing behaviour unchanged).
        DB::table('withdrawals')->whereNull('gross_amount')->update([
            'gross_amount' => DB::raw('amount'), 'fee_amount' => 0, 'net_amount' => DB::raw('amount'), 'fee_status' => 'none',
        ]);

        DB::table('withdrawals')->whereNull('transaction_reference')->orderBy('id')->each(
            fn ($w) => DB::table('withdrawals')->where('id', $w->id)->update(['transaction_reference' => 'WD-' . str_pad((string) $w->id, 8, '0', STR_PAD_LEFT)])
        );

        // Safe seeds.
        DB::table('referral_policies')->insert([
            'reward_amount' => '5.00', 'currency' => 'USD', 'policy_approved' => false, 'effective_from' => now(),
            'criteria' => json_encode([
                'require_kyc_approved' => false, 'require_approved_deposit' => false, 'require_investment' => false,
                'require_completed_cycle' => false, 'referrer_must_be_active' => false,
                'max_rewards_per_referrer' => null, 'max_total_value_per_referrer' => null,
            ]),
            'reason' => 'Initial default: USD 5.00 per eligible referral per the 24 Oct 2026 task document. NOT approved - awaiting Management definition of an eligible activated referral.',
            'changed_by_name' => 'system', 'created_at' => now(),
        ]);
        DB::table('withdrawal_fee_policies')->insert([
            'fee_type' => 'none', 'currency' => 'USD', 'is_active' => false, 'policy_approved' => false, 'effective_from' => now(),
            'reason' => 'Initial state: no withdrawal fee. No fee policy has been approved by Management.', 'configured_by_name' => 'system', 'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_fee_events');
        Schema::table('withdrawals', function (Blueprint $t) {
            $t->dropUnique(['transaction_reference']);
            $t->dropUnique(['idempotency_key']);
            $t->dropIndex(['fee_status']);
        });
        Schema::table('withdrawals', function (Blueprint $t) {
            $t->dropColumn(['gross_amount', 'fee_amount', 'net_amount', 'fee_currency', 'fee_policy_id', 'fee_calculation', 'fee_status', 'fee_confirmed_at', 'transaction_reference', 'idempotency_key']);
        });
        Schema::dropIfExists('withdrawal_fee_policies');
        Schema::dropIfExists('referral_reward_events');
        Schema::dropIfExists('referral_rewards');
        Schema::dropIfExists('referral_events');
        Schema::dropIfExists('referral_policies');
    }
};
