<?php
// NOTE: this table belonged to the earlier "investment basis" gate, superseded by the Board decision (current verified eligible principal).
// It is no longer used by the application. It is kept only so migration history stays consistent where it was already applied.
// Records SSI's WRITTEN decision on what "minimum eligible investment" measures. Additive, append-only. Empty by default,
// which keeps every criterion that measures total_invested INACTIVE (activation is refused) — nothing is decided here.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_investment_basis_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('basis', 40);                    // cumulative_verified_investment | outstanding_capital
            $table->string('status', 12);                   // confirmed | revoked
            $table->string('approval_reference', 160);      // reference of the written SSI decision
            $table->text('note')->nullable();
            $table->unsignedBigInteger('decided_by');
            $table->string('decided_by_name', 120);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_investment_basis_decisions');
    }
};
