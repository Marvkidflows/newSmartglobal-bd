<?php
// LOCATION: app/Services/Rank/CurrencyPolicyService.php
//
// The single source of truth for "has SSI's currency policy been confirmed?". It replaces the old environment flag
// (RANKS_CURRENCY_CONFIRMED), which needed .env edits and left no record of who decided what.
//
// Two-step approval, both attributable and audited (existing Financial audit trail):
//   1. REVIEW   — Financial Team (or Admin) reviews the exact rules + the current record counts.      submitReview()
//   2. CONFIRM  — an Administrator confirms them on Management's behalf; by default a DIFFERENT person   confirm()
//                 from the reviewer. Management also states how records still lacking a verified USD
//                 value are treated (accept their exclusion, or require the queue to be cleared first).
//
// A confirmation is bound to a hash of the rules (and the related config values). If the rules change later, the
// confirmation is STALE and money criteria lock again until the new rules are reviewed and confirmed.
//
// NOTHING here approves a policy automatically, and nothing here edits investments, deposits, balances or profits.

namespace App\Services\Rank;

use App\Models\RankCurrencyPolicy;
use App\Models\User;
use App\Services\FinancialAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CurrencyPolicyService
{
    public const HANDLING_ACCEPT = 'accept_exclusions';
    public const HANDLING_CLEAR  = 'require_clear';
    public const HANDLING        = [self::HANDLING_ACCEPT, self::HANDLING_CLEAR];

    public const ADMIN_POLICY_PATH = '/admin/ranks/policy';
    public const ADMIN_QUEUE_PATH  = '/admin/ranks/currency';

    public function __construct(
        protected UsdValuationService $valuations,
        protected FinancialAuditService $audit,
    ) {}

    // ── The rules being approved ──────────────────────────────────────────────

    /** The exact rule set the system applies. This is what a reviewer reads and what a confirmation is bound to. */
    public function rules(): array
    {
        $extra = array_keys((array) config('ranks.extra_currencies', []));
        sort($extra);

        return [
            'ranking_currency' => (string) config('ranks.ranking_currency', 'USD'),
            'conversion' => 'Verified USD value = recorded amount × the recorded exchange rate to USD, rounded half-up to the cent. A USD amount uses rate 1. No other conversion exists.',
            'never_assumed_usd' => 'A stored amount carries no currency. It is never treated as USD until a person verifies it.',
            'non_usd_requires' => ['exchange rate to USD', 'where the rate came from', 'the date the rate applies to'],
            'excluded_from_totals' => [
                'records with no verified USD value (they count as 0 and are listed for review)',
                'records whose amount changed after verification (stale until re-verified)',
                'records that look like duplicates, until Financial confirms they are separate',
                'cancelled investments and unapproved deposits',
            ],
            'measures' => [
                'total_invested'            => 'Verified USD total of non-cancelled investments (active + completed).',
                'active_investment_amount'  => 'Verified USD total of investments whose status is active.',
                'total_approved_deposits'   => 'Verified USD total of approved deposits. Kept separate: deposits are never counted as investments.',
            ],
            'rate_deviation_percent'            => (float) config('ranks.rate_deviation_percent'),
            'rate_date_tolerance_days'          => (int) config('ranks.rate_date_tolerance_days'),
            'approved_amount_tolerance_percent' => (float) config('ranks.approved_amount_tolerance_percent'),
            'duplicate_window_seconds'          => (int) config('ranks.duplicate_window_seconds'),
            'additional_currency_codes'         => $extra,
        ];
    }

    public function rulesHash(): string
    {
        return hash('sha256', json_encode($this->rules(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // ── State ─────────────────────────────────────────────────────────────────

    public function current(): ?RankCurrencyPolicy
    {
        return RankCurrencyPolicy::query()->where('status', RankCurrencyPolicy::CONFIRMED)->orderByDesc('version')->first();
    }

    public function pending(): ?RankCurrencyPolicy
    {
        return RankCurrencyPolicy::query()->where('status', RankCurrencyPolicy::PENDING)->orderByDesc('version')->first();
    }

    /** True only while a confirmed policy exists AND the rules are exactly the ones that were confirmed. */
    public function isConfirmed(): bool
    {
        $c = $this->current();
        return $c !== null && hash_equals((string) $c->rules_hash, $this->rulesHash());
    }

    /** unconfirmed | pending_confirmation | confirmed | stale | revoked */
    public function state(): string
    {
        $hash = $this->rulesHash();
        $c = $this->current();
        if ($c && hash_equals((string) $c->rules_hash, $hash)) return 'confirmed';

        $p = $this->pending();
        if ($p && hash_equals((string) $p->rules_hash, $hash)) return 'pending_confirmation';

        if ($c) return 'stale';

        $latest = RankCurrencyPolicy::query()->orderByDesc('version')->first();
        return $latest && $latest->status === RankCurrencyPolicy::REVOKED ? 'revoked' : 'unconfirmed';
    }

    // ── Step 1: review (Financial Team / Admin) ───────────────────────────────

    public function submitReview(User $actor, string $note): RankCurrencyPolicy
    {
        $note = trim($note);
        if (mb_strlen($note) < 10) {
            throw ValidationException::withMessages(['note' => ['Describe what you reviewed (at least 10 characters).']]);
        }

        return DB::transaction(function () use ($actor, $note) {
            RankCurrencyPolicy::query()->where('status', RankCurrencyPolicy::PENDING)->lockForUpdate()->get()
                ->each(fn ($p) => $p->forceFill(['status' => RankCurrencyPolicy::SUPERSEDED])->save());

            $summary = $this->valuations->summary();
            $policy = RankCurrencyPolicy::create([
                'version'          => ((int) RankCurrencyPolicy::query()->max('version')) + 1,
                'status'           => RankCurrencyPolicy::PENDING,
                'ranking_currency' => (string) config('ranks.ranking_currency', 'USD'),
                'rules'            => $this->rules(),
                'rules_hash'       => $this->rulesHash(),
                'reviewed_by'      => $actor->id,
                'reviewed_by_name' => $actor->name ?? $actor->full_name ?? 'Unknown',
                'reviewed_by_role' => $actor->role,
                'reviewed_at'      => now(),
                'review_note'      => $note,
                'review_snapshot'  => $summary,
            ]);

            $this->audit->record($actor, 'currency_policy.reviewed', 'currency_policy', $policy->id, null, null,
                ['version' => $policy->version, 'rules_hash' => $policy->rules_hash, 'records_needing_review' => $summary['needs_review']], $note);

            return $policy;
        });
    }

    // ── Step 2: confirmation (Admin, on Management's behalf) ──────────────────

    /**
     * @param int|null $acknowledgedCount  required when accepting exclusions while records still need review: it must equal
     *                                     the number currently needing review, proving the confirmer saw the real figure.
     */
    public function confirm(User $actor, string $note, string $handling, ?int $acknowledgedCount = null): RankCurrencyPolicy
    {
        $errors = [];
        $note = trim($note);
        if (mb_strlen($note) < 10) $errors['note'][] = 'Record the Management decision you are confirming (at least 10 characters).';
        if (!in_array($handling, self::HANDLING, true)) $errors['unverified_handling'][] = 'Choose how records without a verified USD value are treated.';
        if ($errors) throw ValidationException::withMessages($errors);

        return DB::transaction(function () use ($actor, $note, $handling, $acknowledgedCount) {
            $pending = RankCurrencyPolicy::query()->where('status', RankCurrencyPolicy::PENDING)->orderByDesc('version')->lockForUpdate()->first();

            if (!$pending) {
                throw ValidationException::withMessages(['policy' => ['There is nothing to confirm yet. The Financial Team (or an Admin) must first review the currency policy.']]);
            }
            if (!hash_equals((string) $pending->rules_hash, $this->rulesHash())) {
                throw ValidationException::withMessages(['policy' => ['The currency rules changed after they were reviewed. They must be reviewed again before confirming.']]);
            }
            if (config('ranks.currency_policy.separate_confirmer', true) && (int) $pending->reviewed_by === (int) $actor->id) {
                throw ValidationException::withMessages(['policy' => ['The person who reviewed the policy cannot also confirm it. Another Administrator must confirm.']]);
            }

            $needs = (int) $this->valuations->summary()['needs_review'];
            if ($handling === self::HANDLING_ACCEPT && $needs > 0 && $acknowledgedCount !== $needs) {
                throw ValidationException::withMessages(['acknowledged_review_count' => [
                    "{$needs} record(s) currently have no verified USD value (or changed, or look duplicated) and will count as 0. To accept that, enter the number {$needs}; "
                    . 'or choose to require the queue to be cleared first.',
                ]]);
            }

            $previous = $this->current();
            if ($previous) $previous->forceFill(['status' => RankCurrencyPolicy::SUPERSEDED])->save();

            $pending->forceFill([
                'status'                => RankCurrencyPolicy::CONFIRMED,
                'confirmed_by'          => $actor->id,
                'confirmed_by_name'     => $actor->name ?? $actor->full_name ?? 'Unknown',
                'confirmed_by_role'     => $actor->role,
                'confirmed_at'          => now(),
                'confirmation_note'     => $note,
                'unverified_handling'   => $handling,
                'accepted_review_count' => $handling === self::HANDLING_ACCEPT ? $needs : 0,
            ])->save();

            $this->audit->record($actor, 'currency_policy.confirmed', 'currency_policy', $pending->id, null,
                $previous ? ['version' => $previous->version, 'status' => 'confirmed'] : null,
                ['version' => $pending->version, 'rules_hash' => $pending->rules_hash, 'unverified_handling' => $handling,
                 'accepted_review_count' => $pending->accepted_review_count, 'reviewed_by' => $pending->reviewed_by_name], $note);

            return $pending->fresh();
        });
    }

    public function revoke(User $actor, string $reason): RankCurrencyPolicy
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => ['Give the reason for withdrawing the confirmation (at least 10 characters).']]);
        }

        return DB::transaction(function () use ($actor, $reason) {
            $policy = RankCurrencyPolicy::query()->where('status', RankCurrencyPolicy::CONFIRMED)->orderByDesc('version')->lockForUpdate()->first();
            if (!$policy) {
                throw ValidationException::withMessages(['policy' => ['No confirmed currency policy exists to withdraw.']]);
            }

            $policy->forceFill([
                'status' => RankCurrencyPolicy::REVOKED, 'revoked_by' => $actor->id,
                'revoked_by_name' => $actor->name ?? $actor->full_name ?? 'Unknown', 'revoked_at' => now(), 'revoke_reason' => $reason,
            ])->save();

            $this->audit->record($actor, 'currency_policy.revoked', 'currency_policy', $policy->id, null,
                ['version' => $policy->version, 'status' => 'confirmed'], ['version' => $policy->version, 'status' => 'revoked'], $reason);

            return $policy->fresh();
        });
    }

    // ── What blocks activation, in plain language ─────────────────────────────

    /**
     * Reasons a money-based criterion cannot be activated right now. Empty = nothing blocks it.
     * Each entry: type, message, action{label,path}, details.
     *
     * @param  string[] $moneyLabels labels of the money fields the criterion uses
     */
    public function blockers(array $moneyLabels): array
    {
        $fields  = implode(', ', $moneyLabels);
        $state   = $this->state();
        $action  = ['label' => 'Open the currency policy', 'path' => self::ADMIN_POLICY_PATH];
        $current = $this->current();
        $pending = $this->pending();

        switch ($state) {
            case 'confirmed':
                if (!$current || $current->unverified_handling !== self::HANDLING_CLEAR) return [];
                $summary = $this->valuations->summary();
                if ($summary['needs_review'] < 1) return [];

                $aff = $this->affected();
                return [[
                    'type'    => 'currency_records',
                    'message' => "Cannot activate yet: this criterion uses a money amount ({$fields}). Currency policy v{$current->version} requires every record to have a verified USD value first, "
                        . "but {$summary['needs_review']} record(s) still need attention — " . $this->describeCounts($summary)
                        . '. Open the Currency (USD) review queue and verify, correct or confirm each one; then activate again.',
                    'action'  => ['label' => 'Open the review queue', 'path' => self::ADMIN_QUEUE_PATH],
                    'details' => ['policy_version' => $current->version, 'counts' => $summary, 'sample_record_ids' => $aff],
                ]];

            case 'pending_confirmation':
                return [[
                    'type'    => 'currency_pending',
                    'message' => "Cannot activate yet: this criterion uses a money amount ({$fields}). The currency policy (v{$pending->version}) was reviewed by {$pending->reviewed_by_name} on "
                        . $pending->reviewed_at->toDateString() . ' and is waiting for an Administrator to confirm it. Open the currency policy to confirm it; until then you can save this criterion as a draft.',
                    'action'  => $action,
                    'details' => ['policy_version' => $pending->version, 'reviewed_by' => $pending->reviewed_by_name],
                ]];

            case 'stale':
                return [[
                    'type'    => 'currency_stale',
                    'message' => "Cannot activate yet: this criterion uses a money amount ({$fields}). The currency rules changed after policy v{$current->version} was confirmed, so that confirmation no longer applies. "
                        . 'The Financial Team must review the current rules and an Administrator must confirm them again.',
                    'action'  => $action,
                    'details' => ['policy_version' => $current->version],
                ]];

            case 'revoked':
                return [[
                    'type'    => 'currency_unconfirmed',
                    'message' => "Cannot activate yet: this criterion uses a money amount ({$fields}). The previously confirmed currency policy was withdrawn, so a new review and confirmation are required. "
                        . 'You can keep this criterion as a draft meanwhile.',
                    'action'  => $action,
                    'details' => [],
                ]];

            default:
                return [[
                    'type'    => 'currency_unconfirmed',
                    'message' => "Cannot activate yet: this criterion uses a money amount ({$fields}) and SSI's currency policy has not been confirmed. Confirmation is required before any money-based criterion can go live. "
                        . 'Step 1: the Financial Team reviews the policy. Step 2: an Administrator confirms it for Management. Open the currency policy to continue — you can keep this criterion as a draft meanwhile.',
                    'action'  => $action,
                    'details' => [],
                ]];
        }
    }

    // ── Reporting ─────────────────────────────────────────────────────────────

    /** Everything the admin/financial screens need in one payload. */
    public function report(): array
    {
        $current = $this->current();
        $pending = $this->pending();
        $summary = $this->valuations->summary();

        return [
            'state'            => $this->state(),
            'confirmed'        => $this->isConfirmed(),
            'ranking_currency' => (string) config('ranks.ranking_currency', 'USD'),
            'rules'            => $this->rules(),
            'rules_hash'       => $this->rulesHash(),
            'current'          => $current ? $this->present($current) : null,
            'pending'          => $pending ? $this->present($pending) : null,
            'records'          => $summary,
            'affected_sample'  => $summary['needs_review'] > 0 ? $this->affected() : null,
            'requires_separate_confirmer' => (bool) config('ranks.currency_policy.separate_confirmer', true),
        ];
    }

    public function history(int $limit = 50): array
    {
        return RankCurrencyPolicy::query()->orderByDesc('version')->limit($limit)->get()->map(fn ($p) => $this->present($p))->all();
    }

    public function present(RankCurrencyPolicy $p): array
    {
        return [
            'id' => $p->id, 'version' => (int) $p->version, 'status' => $p->status, 'ranking_currency' => $p->ranking_currency,
            'rules_hash' => $p->rules_hash,
            'rules_match_current' => hash_equals((string) $p->rules_hash, $this->rulesHash()),
            'reviewed_by_id' => $p->reviewed_by, 'confirmed_by_id' => $p->confirmed_by,
            'reviewed_by' => $p->reviewed_by_name, 'reviewed_by_role' => $p->reviewed_by_role,
            'reviewed_at' => $p->reviewed_at?->toIso8601String(), 'review_note' => $p->review_note,
            'confirmed_by' => $p->confirmed_by_name, 'confirmed_by_role' => $p->confirmed_by_role,
            'confirmed_at' => $p->confirmed_at?->toIso8601String(), 'confirmation_note' => $p->confirmation_note,
            'unverified_handling' => $p->unverified_handling, 'accepted_review_count' => $p->accepted_review_count,
            'revoked_by' => $p->revoked_by_name, 'revoked_at' => $p->revoked_at?->toIso8601String(), 'revoke_reason' => $p->revoke_reason,
        ];
    }

    public function describeCounts(array $s): string
    {
        $parts = [];
        if ($s['investments']['unverified'])         $parts[] = $s['investments']['unverified'] . ' investment(s) with no verified USD value';
        if ($s['investments']['stale'])              $parts[] = $s['investments']['stale'] . ' investment(s) changed since verification';
        if ($s['investments']['duplicate_suspects']) $parts[] = $s['investments']['duplicate_suspects'] . ' investment(s) that look like duplicates';
        if ($s['deposits']['unverified'])            $parts[] = $s['deposits']['unverified'] . ' approved deposit(s) with no verified USD value';
        if ($s['deposits']['stale'])                 $parts[] = $s['deposits']['stale'] . ' deposit(s) changed since verification';

        return implode('; ', $parts);
    }

    /** First few record ids per problem, so an admin can see exactly what is affected. */
    public function affected(int $limit = 10): array
    {
        $base = fn (string $table, string $type) => DB::table("$table as t")->leftJoin('usd_valuations as v', function ($j) use ($type) {
            $j->on('v.valuable_id', '=', 't.id')->where('v.valuable_type', '=', $type)
              ->whereIn('v.id', function ($q) use ($type) {
                  $q->from('usd_valuations')->selectRaw('MAX(id)')->where('valuable_type', $type)->groupBy('valuable_id');
              });
        });
        $ids = fn ($q) => $q->orderBy('t.id')->limit($limit)->pluck('t.id')->map(fn ($x) => (int) $x)->all();

        $inv = fn () => $base('investment_accounts', 'investment')->where('t.status', '!=', 'cancelled');
        $dep = fn () => $base('deposits', 'deposit')->where('t.status', '=', 'approved');

        return [
            'investments_unverified' => $ids($inv()->whereNull('v.id')),
            'investments_stale'      => $ids($inv()->whereNotNull('v.id')->whereColumn('v.original_amount', '!=', 't.amount')),
            'investments_duplicates' => array_slice($this->valuations->duplicateSuspectIds(), 0, $limit),
            'deposits_unverified'    => $ids($dep()->whereNull('v.id')),
            'deposits_stale'         => $ids($dep()->whereNotNull('v.id')->whereColumn('v.original_amount', '!=', 't.amount')),
        ];
    }
}
