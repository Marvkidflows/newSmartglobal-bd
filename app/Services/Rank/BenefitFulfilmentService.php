<?php
// LOCATION: app/Services/Rank/BenefitFulfilmentService.php
//
// Phase D (OPTIONAL patch 05). Practical fulfilment workflow for STAFF-DELIVERED benefits (fulfilment = 'operational').
//   request -> assign -> start -> complete(evidence)        decline / cancel (with a reason) from any open state
// Guarantees: a request is only possible for an operational, published, non-financial, non-consent benefit that is ACTIVE (or subject to
// eligibility) and that the investor's CURRENT rank entitles them to; "completed" needs a completion reference (so an admin switching a
// catalogue entry on is never mistaken for delivery); every step is one transaction under a row lock and writes the existing append-only
// rank_benefit_audit; a repeated request returns the existing open one. It moves no money and creates no financial entitlement.
// Not built here (needs your decision): investor notification wording/channel, and the admin screen. See IMPLEMENTATION_REPORT.md.

namespace App\Services\Rank;

use App\Models\BenefitFulfilment;
use App\Models\InvestorRank;
use App\Models\RankBenefit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BenefitFulfilmentService
{
    /** @var array<string, string[]> allowed transitions */
    private const TRANSITIONS = [
        BenefitFulfilment::REQUESTED   => [BenefitFulfilment::ASSIGNED, BenefitFulfilment::DECLINED, BenefitFulfilment::CANCELLED],
        BenefitFulfilment::ASSIGNED    => [BenefitFulfilment::IN_PROGRESS, BenefitFulfilment::ASSIGNED, BenefitFulfilment::DECLINED, BenefitFulfilment::CANCELLED],
        BenefitFulfilment::IN_PROGRESS => [BenefitFulfilment::COMPLETED, BenefitFulfilment::DECLINED, BenefitFulfilment::CANCELLED],
    ];

    public function __construct(protected RankBenefitService $audit) {}

    public function request(User $investor, RankBenefit $benefit, ?User $actor = null, ?string $note = null, ?string $dueDate = null): BenefitFulfilment
    {
        return DB::transaction(function () use ($investor, $benefit, $actor, $note, $dueDate) {
            $u = User::whereKey($investor->id)->lockForUpdate()->firstOrFail();
            $this->assertRequestable($u, $benefit);

            $open = BenefitFulfilment::where('user_id', $u->id)->where('rank_benefit_id', $benefit->id)->whereIn('status', BenefitFulfilment::OPEN)->first();
            if ($open) return $open;   // idempotent: one open request per investor and benefit

            $f = BenefitFulfilment::create(['user_id' => $u->id, 'rank_benefit_id' => $benefit->id, 'benefit_key' => $benefit->key, 'status' => BenefitFulfilment::REQUESTED,
                'requested_at' => now(), 'due_date' => $dueDate, 'notes' => $note, 'created_by' => ($actor ?? $u)->id]);
            $this->audit->audit('fulfilment', $f->id, 'fulfilment_requested', $actor ?? $u, null, ['benefit_key' => $benefit->key, 'user_id' => $u->id, 'due_date' => $dueDate], $note);
            return $f;
        });
    }

    public function assign(BenefitFulfilment $f, User $assignee, User $actor, ?string $dueDate = null): BenefitFulfilment
    {
        if (!in_array($assignee->role, ['admin', 'financial'], true)) $this->fail('Only an SSI staff member can be assigned.');
        return $this->move($f, BenefitFulfilment::ASSIGNED, $actor, 'fulfilment_assigned', null, fn (BenefitFulfilment $x) => $x->forceFill([
            'assigned_to' => $assignee->id, 'assigned_to_name' => $assignee->name ?? $assignee->full_name ?? 'Staff', 'due_date' => $dueDate ?? $x->due_date,
        ]));
    }

    public function start(BenefitFulfilment $f, User $actor): BenefitFulfilment
    {
        return $this->move($f, BenefitFulfilment::IN_PROGRESS, $actor, 'fulfilment_started');
    }

    /** Completion REQUIRES evidence: a reference to the briefing record, ticket, session or message that shows the service was delivered. */
    public function complete(BenefitFulfilment $f, string $completionReference, User $actor): BenefitFulfilment
    {
        if (mb_strlen(trim($completionReference)) < 5) $this->fail('A completion reference (evidence that the service was delivered) is required.');
        return $this->move($f, BenefitFulfilment::COMPLETED, $actor, 'fulfilment_completed', $completionReference, fn (BenefitFulfilment $x) => $x->forceFill([
            'completion_reference' => trim($completionReference), 'completed_at' => now(),
        ]));
    }

    public function decline(BenefitFulfilment $f, string $reason, User $actor): BenefitFulfilment
    {
        if (mb_strlen(trim($reason)) < 5) $this->fail('A reason is required.');
        return $this->move($f, BenefitFulfilment::DECLINED, $actor, 'fulfilment_declined', $reason);
    }

    public function cancel(BenefitFulfilment $f, string $reason, User $actor): BenefitFulfilment
    {
        if (mb_strlen(trim($reason)) < 5) $this->fail('A reason is required.');
        return $this->move($f, BenefitFulfilment::CANCELLED, $actor, 'fulfilment_cancelled', $reason);
    }

    // ── internals ───────────────────────────────────────────────────────────────────────────────────────

    protected function move(BenefitFulfilment $f, string $to, User $actor, string $action, ?string $reason = null, ?callable $apply = null): BenefitFulfilment
    {
        return DB::transaction(function () use ($f, $to, $actor, $action, $reason, $apply) {
            $x = BenefitFulfilment::whereKey($f->id)->lockForUpdate()->firstOrFail();
            $from = $x->status;
            if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) $this->fail("A fulfilment that is '{$from}' cannot become '{$to}'.");
            if ($to === BenefitFulfilment::COMPLETED && !$x->assigned_to) $this->fail('Assign a responsible team member before completing.');

            $before = ['status' => $from, 'assigned_to' => $x->assigned_to, 'due_date' => optional($x->due_date)->toDateString()];
            $x->status = $to;
            if ($apply) $apply($x);
            $x->save();
            $this->audit->audit('fulfilment', $x->id, $action, $actor, $before, ['status' => $to, 'assigned_to' => $x->assigned_to, 'completion_reference' => $x->completion_reference], $reason);
            return $x;
        });
    }

    protected function assertRequestable(User $investor, RankBenefit $b): void
    {
        if ($b->fulfilment !== 'operational') $this->fail('Only staff-delivered (operational) benefits have a fulfilment workflow.');
        if ($b->is_financial) $this->fail('A financial benefit can never be fulfilled through this workflow.');
        if ($b->requires_consent) $this->fail('A consent-dependent benefit is handled through the consent flow, not this workflow.');
        if (!$b->is_published || !in_array($b->status, [RankBenefit::STATUS_ACTIVE, RankBenefit::STATUS_ELIGIBILITY], true)) $this->fail('This benefit is not available.');
        if ($b->effective_from && now()->toDateString() < $b->effective_from->toDateString()) $this->fail('This benefit is not yet in effect.');
        if ($b->effective_to && now()->toDateString() > $b->effective_to->toDateString()) $this->fail('This benefit has expired.');

        $held = $investor->investor_rank_id ? InvestorRank::find($investor->investor_rank_id) : null;
        $need = InvestorRank::find($b->investor_rank_id);
        $entitled = $held && $need && ($b->cumulative ? $held->level >= $need->level : $held->level === $need->level);
        if (!$entitled) $this->fail('The investor\'s current rank does not entitle them to this benefit.');
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['fulfilment' => [$message]]);
    }
}
