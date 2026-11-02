<?php
// LOCATION: app/Services/Rank/RankBenefitService.php
//
// The rank BENEFITS catalogue: idempotent population, what an investor sees, and the audited admin changes.
//
// Hard rules enforced HERE (not only in the UI):
//  • syncDefaults() only INSERTS keys that do not exist. It never updates a row, so Management's edits survive.
//  • A benefit is never deleted — it is suspended — and every change is written to rank_benefit_audit.
//  • A financial benefit (is_financial) can NEVER be switched on through this service; financial incentives go
//    through RankIncentiveService's approval chain.
//  • Switching on any benefit that needs approval requires a written approval reference and the approve ability.
//  • Nothing here reads or writes balances, investments, returns or rank assignments.

namespace App\Services\Rank;

use App\Models\InvestorConsentLog;
use App\Models\InvestorRank;
use App\Models\InvestorRankChange;
use App\Models\LoanProgrammeSetting;
use App\Models\RankBenefit;
use App\Models\RankBenefitAudit;
use App\Models\RankReview;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RankBenefitService
{
    // ── Population ──────────────────────────────────────────────────────────────────────────────────────

    /** Insert every default benefit that is missing. Existing rows are never touched. @return array{created:int, skipped:int, missing_ranks:string[]} */
    public function syncDefaults(): array
    {
        $ranks = InvestorRank::pluck('id', 'key');
        $created = 0; $skipped = 0; $missing = [];

        foreach (BenefitDefaults::all() as $d) {
            $rankId = $ranks[$d['rank']] ?? null;
            if (!$rankId) { $missing[$d['rank']] = $d['rank']; continue; }
            if (RankBenefit::where('key', $d['key'])->exists()) { $skipped++; continue; }

            try {
                $row = RankBenefit::create([
                    'key' => $d['key'], 'investor_rank_id' => $rankId, 'category' => $d['category'], 'title' => $d['title'],
                    'description' => $d['description'], 'status' => $d['status'], 'fulfilment' => $d['fulfilment'],
                    'is_financial' => $d['is_financial'], 'requires_approval' => $d['requires_approval'],
                    'requires_consent' => $d['requires_consent'], 'cumulative' => $d['cumulative'], 'display_order' => $d['display_order'],
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                $skipped++; continue;   // another run inserted the same key a moment ago: leave it untouched
            }
            $this->audit('benefit', $row->id, 'seeded', null, null, $row->snapshot(), 'Loaded from the SSI Investor Rank, Benefits and Privileges Framework.');
            $created++;
        }

        LoanProgrammeSetting::current();   // makes sure the (closed) lending switchboard row exists

        return ['created' => $created, 'skipped' => $skipped, 'missing_ranks' => array_values($missing)];
    }

    // ── Audit (single writer) ───────────────────────────────────────────────────────────────────────────

    public function audit(string $type, ?int $id, string $action, ?User $actor, ?array $previous, ?array $new, ?string $reason = null): RankBenefitAudit
    {
        return RankBenefitAudit::create([
            'subject_type' => $type, 'subject_id' => $id, 'action' => $action,
            'actor_id' => $actor?->id, 'actor_name' => $this->actorName($actor), 'actor_role' => $actor?->role ?? 'system',
            'previous' => $previous, 'new' => $new, 'reason' => $reason, 'created_at' => now(),
        ]);
    }

    public function actorName(?User $actor): string
    {
        return $actor ? ($actor->name ?? $actor->full_name ?? 'Admin') : RankAuditService::SYSTEM_ACTOR_NAME;
    }

    // ── What an investor sees ───────────────────────────────────────────────────────────────────────────

    /** Effective status of one benefit for display. Pure: no writes. @return array{status:string, note:?string} */
    public function effective(RankBenefit $b, bool $consent, bool $loanOpen, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $status = $b->status; $note = null;

        if ($status !== RankBenefit::STATUS_SUSPENDED) {
            if ($b->effective_from && $b->effective_from->gt($today)) {
                return ['status' => RankBenefit::STATUS_SUSPENDED, 'note' => 'Not yet in effect — starts ' . $b->effective_from->toFormattedDateString() . '.'];
            }
            if ($b->effective_to && $b->effective_to->lt($today)) {
                return ['status' => RankBenefit::STATUS_SUSPENDED, 'note' => 'Ended on ' . $b->effective_to->toFormattedDateString() . '.'];
            }
        }
        if ($status === RankBenefit::STATUS_SUSPENDED) {
            return ['status' => $status, 'note' => 'Temporarily suspended or unavailable.'];
        }

        if ($b->category === 'loan') {
            if ($b->fulfilment === 'platform') {
                // The application pathway itself needs no separate approval: it follows the lending programme switch.
                return $loanOpen
                    ? ['status' => RankBenefit::STATUS_ELIGIBILITY, 'note' => 'You may apply; eligibility does not guarantee approval.']
                    : ['status' => RankBenefit::STATUS_PENDING, 'note' => 'The lending programme has not been authorized and activated yet.'];
            }
            if (!$loanOpen && in_array($status, [RankBenefit::STATUS_ACTIVE, RankBenefit::STATUS_ELIGIBILITY], true)) {
                return ['status' => RankBenefit::STATUS_PENDING, 'note' => 'The lending programme has not been authorized and activated yet.'];
            }
        }

        if ($b->requires_consent && in_array($status, [RankBenefit::STATUS_ACTIVE, RankBenefit::STATUS_ELIGIBILITY], true) && !$consent) {
            return ['status' => RankBenefit::STATUS_ELIGIBILITY, 'note' => 'Applies only if you give your consent.'];
        }
        if ($status === RankBenefit::STATUS_PENDING) $note = 'Awaiting SSI management approval.';

        return ['status' => $status, 'note' => $note];
    }

    /** Everything the investor benefits page needs. READ-ONLY. */
    public function investorView(User $user): array
    {
        $ranks    = InvestorRank::orderBy('level')->get();
        $active   = ($user->rank_status ?? 'active') === 'active';
        $current  = ($active && $user->investor_rank_id) ? $ranks->firstWhere('id', $user->investor_rank_id) : null;
        $consent  = $this->hasConsent($user);
        $loanOpen = LoanProgrammeSetting::current()->isOpen();
        $today    = Carbon::today();
        $q        = RankBenefit::where('is_published', true);
        if (LoanService::locked()) $q->where('category', '!=', 'loan');   // Board §6: no investor-facing suggestion of credit while lending is unauthorised
        $byRank   = $q->orderBy('display_order')->orderBy('id')->get()->groupBy('investor_rank_id');

        $out = $ranks->map(function (InvestorRank $rank) use ($byRank, $current, $consent, $loanOpen, $today) {
            $benefits = ($byRank[$rank->id] ?? collect())->map(function (RankBenefit $b) use ($rank, $current, $consent, $loanOpen, $today) {
                $e = $this->effective($b, $consent, $loanOpen, $today);
                $applies = $current && ($rank->level === $current->level || ($rank->level < $current->level && $b->cumulative));

                return [
                    'key' => $b->key, 'title' => $b->title, 'description' => $b->description, 'category' => $b->category,
                    'status' => $e['status'], 'status_label' => RankBenefit::STATUS_LABELS[$e['status']], 'note' => $e['note'],
                    'applies_to_you' => (bool) $applies, 'is_financial' => (bool) $b->is_financial, 'requires_consent' => (bool) $b->requires_consent,
                    'effective_from' => $b->effective_from?->toDateString(), 'effective_to' => $b->effective_to?->toDateString(),
                ];
            })->values()->all();

            return $rank->badge() + [
                'is_current'    => (bool) ($current && $current->id === $rank->id),
                'roi_treatment' => BenefitDefaults::ROI_TREATMENT[$rank->key] ?? null,
                'benefits'      => $benefits,
            ];
        })->values()->all();

        return [
            'current_rank_key' => $current?->key,
            'rank_status'      => $user->rank_status ?? 'active',
            'ranks'            => $out,
            'history'          => $this->rankHistory($user),
            'consent'          => ['recognition_communications' => $consent],
            'loan_disclaimer'  => LoanService::locked() ? null : BenefitDefaults::LOAN_DISCLAIMER,
            'status_labels'    => RankBenefit::STATUS_LABELS,
            'notice'           => 'Rank never changes your contractual investment return. Benefits marked "Pending approval" are proposals that SSI management has not approved yet and are not available.',
        ];
    }

    /**
     * Is at least one of these benefits genuinely available to this investor RIGHT NOW? True only when the investor holds an
     * active rank at or above the benefit's rank (cumulative), the benefit is published and in its effective dates, and its
     * effective status is active / subject-to-eligibility — i.e. a benefit still "pending approval" never unlocks anything.
     */
    public function enabledFor(User $user, array $keys): bool
    {
        if (($user->rank_status ?? 'active') !== 'active' || !$user->investor_rank_id) return false;
        $current = InvestorRank::find($user->investor_rank_id);
        if (!$current) return false;

        $consent = $this->hasConsent($user);
        $loanOpen = LoanProgrammeSetting::current()->isOpen();

        foreach (RankBenefit::with('rank')->whereIn('key', $keys)->where('is_published', true)->get() as $b) {
            $applies = $b->rank && ($b->rank->level === $current->level || ($b->rank->level < $current->level && $b->cumulative));
            if (!$applies) continue;
            $e = $this->effective($b, $consent, $loanOpen);
            if (in_array($e['status'], [RankBenefit::STATUS_ACTIVE, RankBenefit::STATUS_ELIGIBILITY], true)) return true;
        }
        return false;
    }

    /**
     * Bronze → Silver (framework §3A): tell a Bronze investor when they meet the Silver requirements.
     * Sent ONLY when management has enabled the "bronze.next-rank-notice" benefit; until then the investor keeps
     * seeing only "review in progress" (the existing behaviour). It says the rank is awarded after review — it never
     * claims the rank has been granted. One notification per review (deterministic id), never duplicated.
     */
    public function notifyNextRankEligibility(RankReview $review): bool
    {
        $review->loadMissing(['investor', 'currentRank', 'proposedRank']);
        $user = $review->investor;
        if (!$user || ($review->currentRank?->key !== 'bronze') || ($review->proposedRank?->key !== 'silver')) return false;
        if (!$this->enabledFor($user, ['bronze.next-rank-notice'])) return false;

        $id = \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'ssi:next-rank-notice:' . $review->id)->toString();
        if (DB::table('notifications')->where('id', $id)->exists()) return false;

        $user->notify(new \App\Notifications\InvestorRankNotification($id, [
            'type' => 'rank_update', 'event' => 'next_rank_eligible', 'title' => 'You meet the Silver requirements',
            'message' => 'You now meet the requirements for the Silver rank. Your rank will be awarded after SSI reviews and approves it.',
            'rank' => $review->proposedRank->badge(),
        ]));
        return true;
    }

    /** The investor's own rank history. Only the facts — no internal reasons, administrator names or snapshots. */
    public function rankHistory(User $user): array
    {
        $labels = [
            'automatic_upgrade' => 'Rank awarded', 'approved_upgrade' => 'Rank awarded', 'manual_assignment' => 'Rank assigned',
            'manual_upgrade' => 'Rank upgraded', 'authorized_override' => 'Rank updated', 'downgrade' => 'Rank changed',
            'suspended' => 'Rank suspended', 'revoked' => 'Rank withdrawn', 'reinstated' => 'Rank reinstated',
        ];

        return InvestorRankChange::with(['previousRank', 'newRank'])
            ->where('investor_id', $user->id)
            ->whereIn('change_type', array_keys($labels))
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn (InvestorRankChange $c) => [
                'date' => $c->created_at?->toIso8601String(), 'event' => $labels[$c->change_type],
                'previous_rank' => $c->previousRank?->badge(), 'new_rank' => $c->newRank?->badge(),
            ])->values()->all();
    }

    // ── Consent ─────────────────────────────────────────────────────────────────────────────────────────

    public function hasConsent(User $user, string $purpose = InvestorConsentLog::RECOGNITION): bool
    {
        $row = InvestorConsentLog::where('user_id', $user->id)->where('purpose', $purpose)->orderByDesc('id')->first();
        return (bool) ($row?->granted);
    }

    /** Record a consent decision (append-only). Re-submitting the current state writes nothing. */
    public function setConsent(User $user, bool $granted, string $purpose = InvestorConsentLog::RECOGNITION): bool
    {
        return DB::transaction(function () use ($user, $granted, $purpose) {
            $latest = InvestorConsentLog::where('user_id', $user->id)->where('purpose', $purpose)->orderByDesc('id')->lockForUpdate()->first();
            if ($latest && (bool) $latest->granted === $granted) return $granted;
            if (!$latest && !$granted) return false;

            InvestorConsentLog::create(['user_id' => $user->id, 'purpose' => $purpose, 'granted' => $granted, 'created_at' => now()]);
            return $granted;
        });
    }

    // ── Admin ───────────────────────────────────────────────────────────────────────────────────────────

    /** Full catalogue for authorised staff (includes unpublished rows and approval fields). */
    public function adminCatalogue(): array
    {
        $byRank = RankBenefit::orderBy('display_order')->orderBy('id')->get()->groupBy('investor_rank_id');

        return InvestorRank::orderBy('level')->get()->map(fn (InvestorRank $r) => $r->badge() + [
            'roi_treatment' => BenefitDefaults::ROI_TREATMENT[$r->key] ?? null,
            'benefits' => ($byRank[$r->id] ?? collect())->map(fn (RankBenefit $b) => $this->adminRow($b))->values()->all(),
        ])->values()->all();
    }

    public function adminRow(RankBenefit $b): array
    {
        return [
            'id' => $b->id, 'key' => $b->key, 'category' => $b->category, 'title' => $b->title, 'description' => $b->description,
            'status' => $b->status, 'status_label' => RankBenefit::STATUS_LABELS[$b->status] ?? $b->status, 'fulfilment' => $b->fulfilment,
            'is_financial' => (bool) $b->is_financial, 'requires_approval' => (bool) $b->requires_approval, 'requires_consent' => (bool) $b->requires_consent,
            'is_published' => (bool) $b->is_published, 'display_order' => (int) $b->display_order,
            'effective_from' => $b->effective_from?->toDateString(), 'effective_to' => $b->effective_to?->toDateString(),
            'approved_by_name' => $b->approved_by_name, 'approved_at' => $b->approved_at?->toIso8601String(),
            'approval_reference' => $b->approval_reference, 'version' => (int) $b->version,
        ];
    }

    /** Edit wording, order, publication and effective dates. Does NOT change the status. */
    public function update(RankBenefit $benefit, array $data, User $actor, string $reason): RankBenefit
    {
        return DB::transaction(function () use ($benefit, $data, $actor, $reason) {
            $b = RankBenefit::lockForUpdate()->findOrFail($benefit->id);
            $from = array_key_exists('effective_from', $data) ? $data['effective_from'] : $b->effective_from?->toDateString();
            $to   = array_key_exists('effective_to', $data) ? $data['effective_to'] : $b->effective_to?->toDateString();
            if ($from && $to && $to < $from) {
                throw ValidationException::withMessages(['effective_to' => 'The end date cannot be before the start date.']);
            }

            $before = $b->snapshot();
            $b->fill(array_intersect_key($data, array_flip(['title', 'description', 'display_order', 'is_published', 'effective_from', 'effective_to'])));
            $b->version = $b->version + 1;
            $b->save();

            $this->audit('benefit', $b->id, 'updated', $actor, $before, $b->snapshot(), $reason);
            return $b->refresh();
        });
    }

    /** Change a benefit's status. @throws ValidationException when a rule below is broken */
    public function setStatus(RankBenefit $benefit, string $status, User $actor, string $reason, ?string $approvalReference = null): RankBenefit
    {
        if (!in_array($status, RankBenefit::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Unknown status.']);
        }

        return DB::transaction(function () use ($benefit, $status, $actor, $reason, $approvalReference) {
            $b = RankBenefit::lockForUpdate()->findOrFail($benefit->id);
            $switchesOn = in_array($status, [RankBenefit::STATUS_ACTIVE, RankBenefit::STATUS_ELIGIBILITY], true);

            if ($switchesOn && $b->is_financial) {
                throw ValidationException::withMessages(['status' => 'A financial incentive cannot be switched on here. It must be configured as an incentive term and activated through the written-approval workflow.']);
            }
            if ($switchesOn && $b->requires_approval) {
                if (!Gate::forUser($actor)->allows('ranks.approve-benefits')) {
                    throw ValidationException::withMessages(['status' => 'You are not authorised to approve benefits.']);
                }
                if (!$approvalReference || mb_strlen(trim($approvalReference)) < 5) {
                    throw ValidationException::withMessages(['approval_reference' => 'Enter the reference of the written SSI management approval (at least 5 characters).']);
                }
            }

            $before = $b->snapshot();
            $b->status = $status;
            if ($switchesOn && $b->requires_approval) {
                $b->approved_by = $actor->id; $b->approved_by_name = $this->actorName($actor);
                $b->approved_at = now(); $b->approval_reference = trim($approvalReference);
            } elseif ($status === RankBenefit::STATUS_PENDING) {
                $b->approved_by = null; $b->approved_by_name = null; $b->approved_at = null; $b->approval_reference = null;
            }
            $b->version = $b->version + 1;
            $b->save();

            $this->audit('benefit', $b->id, match ($status) {
                RankBenefit::STATUS_SUSPENDED => 'suspended',
                RankBenefit::STATUS_PENDING   => 'returned_to_pending',
                default                       => 'enabled',
            }, $actor, $before, $b->snapshot(), $reason);

            return $b->refresh();
        });
    }

    /** Add a custom benefit. Always starts as pending approval; it can never start active or financial-active. */
    public function create(array $data, User $actor, string $reason): RankBenefit
    {
        return DB::transaction(function () use ($data, $actor, $reason) {
            $rank = InvestorRank::findOrFail($data['investor_rank_id']);
            $key = $rank->key . '.' . Str::slug($data['title']);
            if (RankBenefit::where('key', $key)->exists()) $key .= '-' . Str::lower(Str::random(4));

            $b = RankBenefit::create([
                'key' => $key, 'investor_rank_id' => $rank->id, 'category' => $data['category'], 'title' => $data['title'],
                'description' => $data['description'], 'status' => RankBenefit::STATUS_PENDING, 'fulfilment' => 'operational',
                'is_financial' => (bool) ($data['is_financial'] ?? false), 'requires_approval' => true,
                'requires_consent' => (bool) ($data['requires_consent'] ?? false), 'cumulative' => true, 'is_published' => true,
                'display_order' => ((int) RankBenefit::where('investor_rank_id', $rank->id)->max('display_order')) + 10,
            ]);
            $this->audit('benefit', $b->id, 'created', $actor, null, $b->snapshot(), $reason);

            return $b;
        });
    }

    public function history(array $filters = [], int $perPage = 25)
    {
        $q = RankBenefitAudit::query()->orderByDesc('id');
        if (!empty($filters['subject_type'])) $q->where('subject_type', $filters['subject_type']);
        if (!empty($filters['subject_id']))   $q->where('subject_id', (int) $filters['subject_id']);

        return $q->paginate($perPage);
    }
}
