<?php
// LOCATION: app/Services/Rank/RankAdditionalCriteriaService.php
//
// The five ADDITIONAL eligibility criteria (spec §3): qualified referrals, current active investment,
// total approved deposits, qualifying investments, longest qualifying duration.
//
//  • Thresholds exist for all six ranks and are KEPT while a criterion is disabled.
//  • A criterion only influences eligibility while it is ENABLED (and usable — see usable()).
//  • Enabling / disabling / changing thresholds / recording a Management policy is Admin-only, needs a
//    reason, is validated, appends to rank_additional_history, and queues re-evaluation.
//  • READ-ONLY toward money: values are derived from verified records; nothing here writes to a
//    financial table.
//  • Values reuse the same verified-USD ledger as the core amount, so every screen agrees.

namespace App\Services\Rank;

use App\Models\InvestorRank;
use App\Models\RankAdditionalCriterion;
use App\Models\RankAdditionalHistory;
use App\Models\RankAdditionalThreshold;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RankAdditionalCriteriaService
{
    public const FIELD_PREFIX = 'additional:';

    /** Per-instance memo: the engine asks for the criteria once per investor per rank. Cleared on every write. */
    protected ?Collection $memo = null;

    public function __construct(protected RankInvestmentLedger $ledger) {}

    /** Money criteria need a CONFIRMED currency policy (Investor Ranks → Currency policy) — the same gate as the core money rules. */
    protected function currencyConfirmed(): bool
    {
        return app(CurrencyPolicyService::class)->isConfirmed();
    }

    // ── Reading configuration ────────────────────────────────────────────────

    /** @return Collection<string, RankAdditionalCriterion> keyed by criterion key, in display order */
    public function criteria(): Collection
    {
        return $this->memo ??= RankAdditionalCriterion::query()->with('thresholds')->orderBy('sort_order')->get()->keyBy('key');
    }

    /** Does this criterion need a Management decision before it can be switched on? */
    public function policyRequired(string $key): ?array
    {
        return AdditionalCriteriaDefaults::policies()[$key] ?? null;
    }

    public function policyChosen(RankAdditionalCriterion $c): ?string
    {
        $def = $this->policyRequired($c->key);
        return $def ? ($c->policy[$def['policy_key']] ?? null) : null;
    }

    /**
     * May this criterion take part in eligibility RIGHT NOW? Enabled AND every precondition holds.
     * (A criterion that is enabled but no longer usable is ignored rather than guessed at.)
     */
    public function usable(RankAdditionalCriterion $c): bool
    {
        if (!$c->enabled) return false;
        if ($c->is_monetary && !$this->currencyConfirmed()) return false;   // same gate as the core money rules
        if ($this->policyRequired($c->key) && !$this->policyChosen($c)) return false;
        return true;
    }

    /** @return Collection<string, RankAdditionalCriterion> */
    public function usableCriteria(): Collection
    {
        return $this->criteria()->filter(fn ($c) => $this->usable($c));
    }

    /** Signature of the optional-criteria configuration that applies to a rank (for rejection de-duplication). */
    public function signature(int $rankId): string
    {
        return sha1(json_encode($this->usableCriteria()->map(fn ($c) => [
            $c->key, $c->thresholds->firstWhere('investor_rank_id', $rankId)?->value, $c->policy,
        ])->values()->all()));
    }

    /** Full matrix for the Admin screen. */
    public function matrix(): array
    {
        $ranks = InvestorRank::orderBy('level')->get();

        return [
            'ranks'    => $ranks->map->badge()->values()->all(),
            'criteria' => $this->criteria()->map(function ($c) use ($ranks) {
                $policyDef = $this->policyRequired($c->key);
                $chosen    = $this->policyChosen($c);
                return [
                    'key' => $c->key, 'label' => $c->label, 'description' => $c->description, 'unit' => $c->unit,
                    'monetary' => (bool) $c->is_monetary, 'enabled' => (bool) $c->enabled,
                    'in_effect' => $this->usable($c),
                    'blocked_reason' => $this->blockedReason($c),
                    'thresholds' => $ranks->mapWithKeys(fn ($r) => [$r->key => $c->thresholds->firstWhere('investor_rank_id', $r->id)?->value])->all(),
                    'policy' => $policyDef ? [
                        'key' => $policyDef['policy_key'], 'question' => $policyDef['question'],
                        'options' => $policyDef['options'], 'chosen' => $chosen,
                        'set_at' => $c->policy_set_at?->toIso8601String(),
                    ] : null,
                    'approval_status' => $c->enabled ? ($this->usable($c) ? 'enabled' : 'enabled_but_blocked') : 'disabled_pending_management_confirmation',
                    'enabled_at' => $c->enabled_at?->toIso8601String(),
                ];
            })->values()->all(),
            'currency_confirmed' => $this->currencyConfirmed(),
        ];
    }

    protected function blockedReason(RankAdditionalCriterion $c): ?string
    {
        if ($c->is_monetary && !$this->currencyConfirmed()) {
            return 'Money criterion: cannot take effect until the currency policy is confirmed (Investor Ranks → Currency policy).';
        }
        if ($this->policyRequired($c->key) && !$this->policyChosen($c)) {
            return 'Management has not yet specified how this criterion is measured.';
        }
        return null;
    }

    // ── Values & per-rank evaluation ─────────────────────────────────────────

    /**
     * Verified qualifying value of every criterion for each investor.
     *
     * @param  int[]  $userIds
     * @return array<int, array<string, array{value: float|int, provisional: bool}>>
     */
    public function valuesFor(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $out = array_fill_keys($userIds, []);
        if (!$userIds) return $out;

        $criteria = $this->criteria();
        $ledger   = $this->ledger->forUsers($userIds);
        $deposits = $this->ledger->depositsForUsers($userIds);

        $referrals = $this->referralCounts($userIds, $this->policyChosen($criteria['qualified_referrals']) ?? 'verified_and_invested');
        $durations = $this->durations($userIds, $ledger, $this->policyChosen($criteria['longest_duration_days']) ?? 'completed');

        foreach ($userIds as $id) {
            $l = $ledger[$id];
            $out[$id] = [
                'qualified_referrals'    => ['value' => (int) ($referrals[$id] ?? 0), 'provisional' => !$this->policyChosen($criteria['qualified_referrals'])],
                'active_investment_usd'  => ['value' => (float) $l['active_usd'], 'provisional' => false],
                'approved_deposits_usd'  => ['value' => (float) $deposits[$id]['total_usd'], 'provisional' => false],
                // Only records whose USD value is verified and current; unverified/stale ones never inflate the count.
                'qualifying_investments' => ['value' => max(0, $l['count'] - count($l['unverified']) - count($l['stale'])), 'provisional' => false],
                'longest_duration_days'  => ['value' => (int) ($durations[$id] ?? 0), 'provisional' => !$this->policyChosen($criteria['longest_duration_days'])],
            ];
        }

        return $out;
    }

    /**
     * Condition rows (same shape the rule evaluator emits) for ONE investor against ONE rank.
     *
     * @param  bool $enabledOnly  true = only criteria in effect (what the engine uses);
     *                            false = all five, for display ("met / not met / enabled?").
     */
    public function conditionsFor(int $userId, int $rankId, bool $enabledOnly = true, ?array $values = null): array
    {
        $values ??= $this->valuesFor([$userId])[$userId] ?? [];
        $rows = [];

        foreach ($this->criteria() as $c) {
            $inEffect = $this->usable($c);
            if ($enabledOnly && !$inEffect) continue;

            $threshold = $c->thresholds->firstWhere('investor_rank_id', $rankId)?->value;
            if ($threshold === null) continue;

            $actual = $values[$c->key]['value'] ?? 0;
            $passed = (float) $actual >= (float) $threshold - 0.000001;
            $fmt    = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.') . ($c->unit ? " {$c->unit}" : '');

            $rows[] = [
                'group_no' => 0,
                'field'    => self::FIELD_PREFIX . $c->key,
                'criterion_key' => $c->key,
                'additional' => true,
                'label'    => $c->label,
                'operator' => '>=',
                'expected' => $threshold,
                'actual'   => $actual,
                'passed'   => $passed,
                'enabled'  => $inEffect,
                'configured_enabled' => (bool) $c->enabled,
                'approval_status' => $c->enabled ? ($inEffect ? 'enabled' : 'enabled_but_blocked') : 'disabled_pending_management_confirmation',
                'provisional' => (bool) ($values[$c->key]['provisional'] ?? false),
                'unit'     => $c->unit,
                'text'     => "{$c->label} ≥ " . $fmt($threshold),
            ];
        }

        return $rows;
    }

    // ── Changing configuration (Admin only; reason + audit; re-evaluate) ─────

    public function setEnabled(string $key, bool $enabled, User $actor, string $reason): RankAdditionalCriterion
    {
        Gate::forUser($actor)->authorize('ranks.manage-criteria');
        $reason = $this->requireReason($reason);

        $result = DB::transaction(function () use ($key, $enabled, $actor, $reason) {
            $c = RankAdditionalCriterion::with('thresholds')->where('key', $key)->lockForUpdate()->firstOrFail();
            if ($c->enabled === $enabled) return [$c, false];

            if ($enabled) {
                $problems = [];
                if ($this->policyRequired($key) && !$this->policyChosen($c)) {
                    $problems[] = 'Record how Management defines this criterion first (' . $this->policyRequired($key)['question'] . ')';
                }
                if ($c->is_monetary && !$this->currencyConfirmed()) {
                    $problems[] = 'This is a money criterion and the currency policy has not been confirmed (Investor Ranks → Currency policy).';
                }
                if ($c->thresholds->count() < InvestorRank::count()) {
                    $problems[] = 'A threshold is missing for at least one rank.';
                }
                if ($problems) throw ValidationException::withMessages(['criterion' => $problems]);
            }

            $before = ['enabled' => (bool) $c->enabled];
            $c->forceFill(['enabled' => $enabled, 'enabled_by' => $enabled ? $actor->id : $c->enabled_by, 'enabled_at' => $enabled ? now() : $c->enabled_at])->save();
            $this->history($key, $enabled ? 'enabled' : 'disabled', $actor, $before, ['enabled' => $enabled], $reason);

            return [$c->fresh('thresholds'), true];
        });

        $this->memo = null;
        if ($result[1]) $this->reevaluate($actor);
        return $result[0];
    }

    /** @param array<string, int|float|string> $thresholds rankKey => value */
    public function setThresholds(string $key, array $thresholds, User $actor, string $reason): RankAdditionalCriterion
    {
        Gate::forUser($actor)->authorize('ranks.manage-criteria');
        $reason = $this->requireReason($reason);

        $ranks = InvestorRank::orderBy('level')->get();
        $c0 = RankAdditionalCriterion::where('key', $key)->firstOrFail();

        $unknown = array_diff(array_keys($thresholds), $ranks->pluck('key')->all());
        if ($unknown) throw ValidationException::withMessages(['thresholds' => ['Unknown rank(s): ' . implode(', ', $unknown)]]);

        $result = DB::transaction(function () use ($key, $thresholds, $actor, $reason, $ranks) {
            $c = RankAdditionalCriterion::with('thresholds')->where('key', $key)->lockForUpdate()->firstOrFail();
            $current = $ranks->mapWithKeys(fn ($r) => [$r->key => $c->thresholds->firstWhere('investor_rank_id', $r->id)?->value])->all();
            $proposed = array_merge($current, array_map(fn ($v) => $v === null ? null : (is_numeric($v) ? (float) $v : $v), $thresholds));

            $errors = [];
            $prev = null;
            foreach ($ranks as $r) {
                $v = $proposed[$r->key] ?? null;
                if ($v === null || !is_numeric($v) || (float) $v < 0) { $errors[] = "{$r->name}: a non-negative number is required."; continue; }
                if ($c->unit !== 'USD' && floor((float) $v) != (float) $v) { $errors[] = "{$r->name}: must be a whole number."; continue; }
                if ($prev && (float) $v < (float) $prev['v'] - 0.000001) {
                    $errors[] = "{$r->name} ({$v}) cannot be lower than {$prev['name']} ({$prev['v']}) — a higher rank must not be easier.";
                }
                $prev = ['v' => $v, 'name' => $r->name];
            }
            if ($errors) throw ValidationException::withMessages(['thresholds' => $errors]);

            $changed = [];
            foreach ($ranks as $r) {
                if (!array_key_exists($r->key, $thresholds)) continue;
                if (abs((float) $proposed[$r->key] - (float) ($current[$r->key] ?? -1)) < 0.000001) continue;
                RankAdditionalThreshold::query()->updateOrInsert(
                    ['criterion_key' => $key, 'investor_rank_id' => $r->id],
                    ['value' => $proposed[$r->key], 'updated_by' => $actor->id, 'updated_at' => now(), 'created_at' => now()]
                );
                $changed[] = $r->key;
            }
            if (!$changed) return [$c, false];

            $this->history($key, 'thresholds_changed', $actor,
                collect($current)->only($changed)->all(), collect($proposed)->only($changed)->all(), $reason);

            return [$c->fresh('thresholds'), $c->enabled];
        });

        $this->memo = null;
        if ($result[1]) $this->reevaluate($actor);
        return $result[0];
    }

    /** Record Management's interpretation (e.g. completed vs elapsed duration). */
    public function setPolicy(string $key, string $choice, User $actor, string $reason): RankAdditionalCriterion
    {
        Gate::forUser($actor)->authorize('ranks.manage-criteria');
        $reason = $this->requireReason($reason);

        $def = $this->policyRequired($key);
        if (!$def) throw ValidationException::withMessages(['policy' => ['This criterion has no Management policy choice.']]);
        if (!array_key_exists($choice, $def['options'])) {
            throw ValidationException::withMessages(['choice' => ['Choose one of: ' . implode(', ', array_keys($def['options']))]]);
        }

        $result = DB::transaction(function () use ($key, $def, $choice, $actor, $reason) {
            $c = RankAdditionalCriterion::where('key', $key)->lockForUpdate()->firstOrFail();
            $before = $c->policy ?? [];
            if (($before[$def['policy_key']] ?? null) === $choice) return [$c, false];

            $c->forceFill(['policy' => array_merge($before, [$def['policy_key'] => $choice]), 'policy_set_by' => $actor->id, 'policy_set_at' => now()])->save();
            $this->history($key, 'policy_set', $actor, $before, $c->policy, $reason);

            return [$c->fresh('thresholds'), $c->enabled];
        });

        $this->memo = null;
        if ($result[1]) $this->reevaluate($actor);
        return $result[0];
    }

    public function history_list(int $limit = 200): array
    {
        return RankAdditionalHistory::orderByDesc('id')->limit($limit)->get()->map(fn ($h) => [
            'id' => $h->id, 'criterion_key' => $h->criterion_key, 'action' => $h->action,
            'actor_name' => $h->actor_name, 'actor_role' => $h->actor_role,
            'previous' => $h->previous, 'new' => $h->new, 'reason' => $h->reason,
            'created_at' => $h->created_at?->toIso8601String(),
        ])->all();
    }

    // ── Internals ────────────────────────────────────────────────────────────

    protected function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) throw ValidationException::withMessages(['reason' => ['A reason of at least 5 characters is required.']]);
        return $reason;
    }

    protected function history(string $key, string $action, User $actor, ?array $previous, ?array $new, string $reason): void
    {
        RankAdditionalHistory::create([
            'criterion_key' => $key, 'action' => $action, 'actor_id' => $actor->id,
            'actor_name' => $actor->name ?? $actor->full_name ?? 'Admin', 'actor_role' => $actor->role,
            'previous' => $previous, 'new' => $new, 'reason' => $reason, 'created_at' => now(),
        ]);
    }

    /** Queue a full re-evaluation after the configuration commits. Never changes a rank by itself. */
    protected function reevaluate(User $actor): void
    {
        rescue(fn () => app(RankEvaluationService::class)->startRun('criteria_change', null, $actor), null, true);
    }

    /** @return array<int,int> referrer id => unique qualified referrals */
    protected function referralCounts(array $userIds, string $rule): array
    {
        $q = DB::table('users as r')
            ->whereIn('r.referred_by', $userIds)
            ->whereColumn('r.id', '!=', 'r.referred_by')        // never count yourself
            ->where('r.role', 'investor')
            ->where(fn ($w) => $w->whereNull('r.status')->orWhere('r.status', 'active'));

        if (in_array($rule, ['verified_account', 'verified_and_invested'], true)) {
            $q->where('r.kyc_status', 'approved')->where('r.kyc_verified', true);
        }
        if ($rule === 'verified_and_invested') {
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('investment_accounts as ia')
                ->whereColumn('ia.user_id', 'r.id')->whereIn('ia.status', ['active', 'completed'])->where('ia.amount', '>', 0));
        }

        // COUNT(DISTINCT r.id): one referred account is one referral however many records point at it.
        return $q->groupBy('r.referred_by')->selectRaw('r.referred_by as rid, COUNT(DISTINCT r.id) as c')->pluck('c', 'rid')
            ->map(fn ($v) => (int) $v)->all();
    }

    /** @return array<int,int> investor id => longest duration in whole days, per Management's chosen basis */
    protected function durations(array $userIds, array $ledger, string $basis): array
    {
        $rows = DB::table('investment_accounts as ia')
            ->leftJoin('investment_plans as p', 'p.id', '=', 'ia.investment_plan_id')
            ->whereIn('ia.user_id', $userIds)->where('ia.status', '!=', 'cancelled')
            ->get(['ia.id', 'ia.user_id', 'ia.status', 'ia.start_date', 'ia.end_date', 'p.duration_days']);

        $today = Carbon::today();
        $out = [];

        foreach ($rows as $r) {
            $u = (int) $r->user_id;
            if (in_array((int) $r->id, $ledger[$u]['duplicates'] ?? [], true)) continue;   // duplicates never count
            if (!$r->start_date) continue;

            $start = Carbon::parse($r->start_date)->startOfDay();

            if ($basis === 'elapsed_active') {
                if ($r->status !== 'active') continue;
                $days = (int) floor($start->diffInDays($today, true));
            } else {   // completed: recorded start → recorded end, and the cycle must genuinely have ended
                if ($r->status !== 'completed' || !$r->end_date || !$this->ledger->cycleHasEnded($r, $today)) continue;
                $days = (int) floor($start->diffInDays(Carbon::parse($r->end_date)->startOfDay(), true));
            }

            $out[$u] = max($out[$u] ?? 0, max(0, $days));
        }

        return $out;
    }
}
