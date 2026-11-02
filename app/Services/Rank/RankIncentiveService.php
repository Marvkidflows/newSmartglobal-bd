<?php
// LOCATION: app/Services/Rank/RankIncentiveService.php
//
// The CONFIGURABLE incentive ("ROI differentiation") records of SSI Investor Rank, Benefits and Privileges Framework §4.
//
// WHAT THIS IS NOT: it does not change any contractual return, profit percentage, payout, balance or investment.
// Nothing in InvestmentSettlementService / the maturity command reads these records. They are configuration and
// audit records that Management can prepare and approve; wiring an approved term into payouts is a separate,
// Management-approved piece of work (see RANK_BENEFITS_MODULE.md).
//
// Lifecycle:  draft → pending_approval → approved → active        (disabled at any time)
//  • No value is ever seeded or defaulted — a person enters it.
//  • Submit needs a value, a start date and written conditions.
//  • Approve needs the approve ability, a written-approval reference, and a DIFFERENT person from the author.
//  • Activate needs an approved term AND the master switch ranks.incentives_activation_enabled (default OFF).
//  • Editing an approved/active term returns it to draft (an approved term can never be changed silently).

namespace App\Services\Rank;

use App\Models\InvestmentPlan;
use App\Models\InvestorRank;
use App\Models\RankIncentiveTerm;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RankIncentiveService
{
    public function __construct(protected RankBenefitService $benefits) {}

    public function list(): array
    {
        return RankIncentiveTerm::with('rank')->orderByDesc('id')->get()->map(fn (RankIncentiveTerm $t) => $this->present($t))->all();
    }

    public function present(RankIncentiveTerm $t): array
    {
        return [
            'id' => $t->id, 'label' => $t->label, 'rank' => $t->rank?->badge(), 'investment_plan_id' => $t->investment_plan_id,
            'kind' => $t->kind, 'value' => $t->value, 'conditions' => $t->conditions, 'status' => $t->status,
            'effective_from' => $t->effective_from?->toDateString(), 'effective_to' => $t->effective_to?->toDateString(),
            'approved_by_name' => $t->approved_by_name, 'approved_at' => $t->approved_at?->toIso8601String(),
            'approval_reference' => $t->approval_reference, 'activated_at' => $t->activated_at?->toIso8601String(),
            'version' => (int) $t->version, 'created_by' => $t->created_by,
        ];
    }

    public function create(array $d, User $actor, string $reason): RankIncentiveTerm
    {
        $this->validateShape($d);

        return DB::transaction(function () use ($d, $actor, $reason) {
            InvestorRank::findOrFail($d['investor_rank_id']);
            $t = RankIncentiveTerm::create([
                'label' => $d['label'], 'investor_rank_id' => $d['investor_rank_id'], 'investment_plan_id' => $d['investment_plan_id'] ?? null,
                'kind' => $d['kind'], 'value' => $d['value'] ?? null, 'conditions' => $d['conditions'] ?? null, 'status' => RankIncentiveTerm::DRAFT,
                'effective_from' => $d['effective_from'] ?? null, 'effective_to' => $d['effective_to'] ?? null, 'created_by' => $actor->id, 'version' => 1,
            ]);
            $this->benefits->audit('incentive', $t->id, 'created', $actor, null, $t->snapshot(), $reason);
            return $t;
        });
    }

    public function update(RankIncentiveTerm $term, array $d, User $actor, string $reason): RankIncentiveTerm
    {
        $this->validateShape($d, partial: true);

        return DB::transaction(function () use ($term, $d, $actor, $reason) {
            $t = RankIncentiveTerm::lockForUpdate()->findOrFail($term->id);
            if ($t->status === RankIncentiveTerm::DISABLED) {
                throw ValidationException::withMessages(['status' => 'A disabled term cannot be edited. Create a new term instead.']);
            }
            $before = $t->snapshot();
            $t->fill(array_intersect_key($d, array_flip(['label', 'investment_plan_id', 'kind', 'value', 'conditions', 'effective_from', 'effective_to'])));
            $this->assertDates($t);
            $this->assertValueForKind($t);

            // Any edit invalidates a previous submission or approval.
            $t->status = RankIncentiveTerm::DRAFT;
            $t->submitted_by = null; $t->submitted_at = null;
            $t->approved_by = null; $t->approved_by_name = null; $t->approved_at = null; $t->approval_reference = null;
            $t->activated_by = null; $t->activated_at = null;
            $t->version = $t->version + 1;
            $t->save();

            $this->benefits->audit('incentive', $t->id, 'edited_returned_to_draft', $actor, $before, $t->snapshot(), $reason);
            return $t->refresh();
        });
    }

    public function submit(RankIncentiveTerm $term, User $actor, string $reason): RankIncentiveTerm
    {
        return $this->transition($term, $actor, $reason, 'submitted', function (RankIncentiveTerm $t) use ($actor) {
            if ($t->status !== RankIncentiveTerm::DRAFT) $this->fail('Only a draft can be submitted for approval.');
            if ($t->value === null || (float) $t->value <= 0) $this->fail('Enter the value Management has specified before submitting.');
            if (!$t->effective_from) $this->fail('Set the effective start date before submitting.');
            if (!$t->conditions || mb_strlen(trim($t->conditions)) < 10) $this->fail('Write the conditions that apply before submitting.');
            $t->status = RankIncentiveTerm::PENDING; $t->submitted_by = $actor->id; $t->submitted_at = now();
        });
    }

    public function approve(RankIncentiveTerm $term, User $actor, string $reason, string $approvalReference): RankIncentiveTerm
    {
        return $this->transition($term, $actor, $reason, 'approved', function (RankIncentiveTerm $t) use ($actor, $approvalReference) {
            if (!Gate::forUser($actor)->allows('ranks.approve-benefits')) $this->fail('You are not authorised to approve incentive terms.');
            if ($t->status !== RankIncentiveTerm::PENDING) $this->fail('Only a term that is pending approval can be approved.');
            if ((int) $t->created_by === (int) $actor->id || (int) $t->submitted_by === (int) $actor->id) {
                $this->fail('A different person from the one who prepared the term must approve it.');
            }
            if (mb_strlen(trim($approvalReference)) < 5) $this->fail('Enter the reference of the written SSI management approval (at least 5 characters).', 'approval_reference');
            $t->status = RankIncentiveTerm::APPROVED;
            $t->approved_by = $actor->id; $t->approved_by_name = $this->benefits->actorName($actor);
            $t->approved_at = now(); $t->approval_reference = trim($approvalReference);
        });
    }

    public function activate(RankIncentiveTerm $term, User $actor, string $reason): RankIncentiveTerm
    {
        return $this->transition($term, $actor, $reason, 'activated', function (RankIncentiveTerm $t) use ($actor) {
            if (!config('ranks.incentives_activation_enabled', false)) {
                $this->fail('Rank-based incentives cannot be activated: the activation switch (RANKS_INCENTIVES_ACTIVATION_ENABLED) is off. Turning it on is an SSI management and financial-policy decision.');
            }
            if (!Gate::forUser($actor)->allows('ranks.approve-benefits')) $this->fail('You are not authorised to activate incentive terms.');
            if ($t->status !== RankIncentiveTerm::APPROVED || !$t->approval_reference || !$t->approved_by) {
                $this->fail('Only a term with a recorded written approval can be activated.');
            }
            $t->status = RankIncentiveTerm::ACTIVE; $t->activated_by = $actor->id; $t->activated_at = now();
        });
    }

    public function disable(RankIncentiveTerm $term, User $actor, string $reason): RankIncentiveTerm
    {
        return $this->transition($term, $actor, $reason, 'disabled', function (RankIncentiveTerm $t) {
            if ($t->status === RankIncentiveTerm::DISABLED) $this->fail('This term is already disabled.');
            $t->status = RankIncentiveTerm::DISABLED;
        });
    }

    // ── internals ───────────────────────────────────────────────────────────────────────────────────────

    protected function transition(RankIncentiveTerm $term, User $actor, string $reason, string $action, callable $mutate): RankIncentiveTerm
    {
        return DB::transaction(function () use ($term, $actor, $reason, $action, $mutate) {
            $t = RankIncentiveTerm::lockForUpdate()->findOrFail($term->id);
            $before = $t->snapshot();
            $mutate($t);
            $t->version = $t->version + 1;
            $t->save();
            $this->benefits->audit('incentive', $t->id, $action, $actor, $before, $t->snapshot(), $reason);
            return $t->refresh();
        });
    }

    protected function validateShape(array $d, bool $partial = false): void
    {
        if (!$partial || array_key_exists('kind', $d)) {
            if (!in_array($d['kind'] ?? null, RankIncentiveTerm::KINDS, true)) $this->fail('Choose a valid incentive type.', 'kind');
        }
        if (!empty($d['investment_plan_id']) && !InvestmentPlan::whereKey($d['investment_plan_id'])->exists()) {
            $this->fail('That investment plan does not exist.', 'investment_plan_id');
        }
        if (isset($d['value']) && $d['value'] !== null && (!is_numeric($d['value']) || (float) $d['value'] < 0)) {
            $this->fail('The value must be a positive number.', 'value');
        }
        if (($d['kind'] ?? null) === 'percentage_bonus' && isset($d['value']) && (float) $d['value'] > 100) {
            $this->fail('A percentage cannot exceed 100.', 'value');
        }
    }

    protected function assertDates(RankIncentiveTerm $t): void
    {
        if ($t->effective_from && $t->effective_to && $t->effective_to->lt($t->effective_from)) {
            $this->fail('The end date cannot be before the start date.', 'effective_to');
        }
    }

    protected function assertValueForKind(RankIncentiveTerm $t): void
    {
        if ($t->kind === 'percentage_bonus' && $t->value !== null && (float) $t->value > 100) $this->fail('A percentage cannot exceed 100.', 'value');
    }

    protected function fail(string $message, string $field = 'status'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
