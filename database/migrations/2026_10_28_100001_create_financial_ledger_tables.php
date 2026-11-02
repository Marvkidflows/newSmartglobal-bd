<?php
// Financial Team decisions (27 Oct 2026) + Board decisions: investment flags, position-level principal movements, the wallet
// ledger with separate classifications, investment allocations (wallet → position, linked) and withdrawal components.
//
// ADDITIVE ONLY. No existing table is altered and no existing row is read, changed or deleted. Every table is append-only at the
// model level. They stay EMPTY until the (feature-flagged) workflows write to them, and rank calculation treats an empty
// principal_movements / investment_flags table as "no reductions / no flags", i.e. exactly today's data.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Disputed / reversed / under-review marks on an investment position (Board §3). Latest row per investment decides.
        Schema::create('investment_flags', function (Blueprint $t) {
            $t->id();
            $t->foreignId('investment_id')->constrained('investment_accounts');
            $t->string('flag_type', 20);                      // disputed | reversed | under_review
            $t->string('action', 10);                         // set | cleared
            $t->text('reason');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name', 120);
            $t->string('actor_role', 30);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['investment_id', 'id']);
        });

        // Position-level principal ledger. remaining principal = position amount − Σ completed reducing movements.
        Schema::create('principal_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('investment_id')->constrained('investment_accounts');
            $t->foreignId('user_id')->constrained('users');
            // release (informational: principal became eligible for settlement) | return_to_wallet | external_payout |
            // withdrawal_principal | correction (signed, approved adjustment)
            $t->string('kind', 24);
            $t->string('status', 12)->default('completed');   // pending | completed
            $t->decimal('amount', 15, 2);                     // position-currency units; positive reduces principal, negative (correction) restores
            $t->string('source_type', 20);                    // redemption | withdrawal | correction
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('source_key', 120)->unique();          // duplicate-deduction guard: one movement per source + position + kind
            $t->unsignedBigInteger('withdrawal_id')->nullable();
            $t->string('entry_group', 40)->nullable();        // links to wallet_ledger_entries
            $t->string('reference', 120)->nullable();         // payout / transaction reference
            $t->text('evidence')->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->string('recorded_by_name', 120);
            $t->unsignedBigInteger('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['investment_id', 'status']);
            $t->index('withdrawal_id');
        });

        // Wallet ledger with separate classifications (Financial Team §1B): pending deposits, cleared wallet funds, allocated
        // investment principal, pending withdrawals, completed payouts (+ principal returned / profit credited).
        Schema::create('wallet_ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->string('entry_group', 40)->index();           // links the legs of ONE movement (e.g. wallet debit + allocation)
            $t->string('entry_key', 120)->nullable()->unique(); // idempotency
            $t->foreignId('user_id')->constrained('users');
            $t->string('classification', 40);
            $t->string('direction', 8);                       // credit | debit | info
            $t->decimal('amount', 15, 2);
            $t->string('currency', 8)->default('USD');
            $t->unsignedBigInteger('deposit_id')->nullable();
            $t->unsignedBigInteger('withdrawal_id')->nullable();
            $t->unsignedBigInteger('investment_id')->nullable();
            $t->unsignedBigInteger('allocation_id')->nullable();
            $t->unsignedBigInteger('principal_movement_id')->nullable();
            $t->string('reference', 120)->nullable();
            $t->decimal('balance_after', 15, 2)->nullable();
            $t->text('note')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name', 120)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['user_id', 'classification']);
        });

        // An investor's investment selection with the plan terms that applied, linked to the wallet debit (Financial Team §1A).
        Schema::create('investment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->unsignedBigInteger('investment_plan_id');
            $t->foreignId('investment_id')->constrained('investment_accounts');
            $t->decimal('amount', 15, 2);
            $t->json('plan_terms');
            $t->string('entry_group', 40)->index();
            $t->string('status', 12)->default('completed');
            $t->timestamp('created_at')->useCurrent();
        });

        // How a withdrawal splits into principal (position-specific) and profit (Financial Team §2D).
        Schema::create('withdrawal_components', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('withdrawal_id')->index();
            $t->unsignedBigInteger('investment_id')->nullable();
            $t->string('component', 10);                      // principal | profit
            $t->decimal('amount', 15, 2);
            $t->string('component_key', 120)->unique();
            $t->text('verification_note')->nullable();        // profit: evidence that it is profit available for withdrawal
            $t->unsignedBigInteger('classified_by')->nullable();
            $t->string('classified_by_name', 120);
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_components');
        Schema::dropIfExists('investment_allocations');
        Schema::dropIfExists('wallet_ledger_entries');
        Schema::dropIfExists('principal_movements');
        Schema::dropIfExists('investment_flags');
    }
};
