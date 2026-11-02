<?php
// Replaces the superseded "investment basis" decision (the Board decided: current verified eligible principal). Records the formal
// sign-offs that must exist before any rank criterion that measures money may be ACTIVATED. Append-only; empty by default.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_policy_decisions', function (Blueprint $t) {
            $t->id();
            $t->string('topic', 40);                          // financial_decisions_adopted | release_authorization
            $t->string('status', 12);                         // confirmed | revoked
            $t->string('approval_reference', 160);
            $t->text('note')->nullable();
            $t->unsignedBigInteger('decided_by');
            $t->string('decided_by_name', 120);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['topic', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_policy_decisions');
    }
};
