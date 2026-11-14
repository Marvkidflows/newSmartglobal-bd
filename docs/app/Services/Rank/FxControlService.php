<?php
// LOCATION: app/Services/Rank/FxControlService.php
//
// Currency controls required by the Financial Team (27 Oct 2026, §4–§5). This service NEVER invents, fetches or assumes an exchange
// rate. It (a) preserves a full, immutable snapshot of each conversion that staff have ALREADY recorded and verified, (b) refuses to treat
// cryptocurrency as rank-eligible principal until that is separately approved, (c) flags stale rates only where a maximum age has been
// approved, and (d) runs the reconciliation queue for historical records with missing, inconsistent or incomplete currency metadata.
// Corrections are new, auditable adjustments — the original record and every earlier snapshot are kept untouched.

namespace App\Services\Rank;

use App\Models\Deposit;
use App\Models\FxApprovedSource;
use App\Models\FxConversionSnapshot;
use App\Models\FxReconciliationItem;
use App\Models\InvestmentAccount;
use App\Models\UsdValuation;
use App\Models\User;
use App\Services\Ledger\LedgerReaders;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FxControlService
{
    public const CRYPTO_METHODS = ['bitcoin', 'ethereum', 'usdt'];
    public const CRYPTO_CODES   = ['BTC', 'ETH', 'USDT', 'USDC', 'BNB', 'TRX', 'SOL', 'XRP', 'LTC', 'DOGE', 'DAI', 'BUSD', 'MATIC'];
    public const REASONS = ['missing_currency', 'missing_rate', 'missing_rate_source', 'stale_rate', 'unsupported_pair', 'no_valuation', 'inconsistent_amount', 'crypto_unapproved'];

    public function __construct(protected RankBenefitService $audit) {}

    public function available(): bool
    {
        return LedgerReaders::has('fx_reconciliation_items') && LedgerReaders::has('fx_conversion_snapshots');
    }

    // ── cryptocurrency (§4E) ────────────────────────────────────────────────────────────────────────────

    public function cryptoValuationBlocked(): bool
    {
        return !config('financial.crypto_rank_valuation_enabled', false);
    }

    public function isCryptoDeposit(Deposit $d): bool
    {
        return in_array(strtolower((string) $d->payment_method), self::CRYPTO_METHODS, true)
            || in_array(strtoupper((string) $d->paid_currency), self::CRYPTO_CODES, true);
    }

    // ── reconciliation queue ────────────────────────────────────────────────────────────────────────────

    public function openItem(string $type, int $id, string $reason, ?string $detail = null): ?FxReconciliationItem
    {
        if (!$this->available()) return null;
        return FxReconciliationItem::firstOrCreate(['subject_type' => $type, 'subject_id' => $id, 'reason_code' => $reason], ['detail' => $detail, 'status' => 'open']);
    }

    /**
     * Read-only scan of financial records; writes ONLY queue rows. Nothing is rewritten and no rate is assigned.
     * @return array<string, int> items opened per reason
     */
    public function scan(): array
    {
        $counts = array_fill_keys(self::REASONS, 0);
        if (!$this->available()) return $counts;
        $open = function (string $type, int $id, string $reason, string $detail) use (&$counts) {
            $existing = FxReconciliationItem::where(['subject_type' => $type, 'subject_id' => $id, 'reason_code' => $reason])->exists();
            if (!$existing) { $this->openItem($type, $id, $reason, $detail); $counts[$reason]++; }
        };

        Deposit::where('status', 'approved')->orderBy('id')->chunkById(500, function ($deposits) use ($open) {
            foreach ($deposits as $d) {
                if ($this->isCryptoDeposit($d) && $this->cryptoValuationBlocked()) { $open('deposit', $d->id, 'crypto_unapproved', 'Cryptocurrency deposit: valuation for rank eligibility is not approved.'); continue; }
                if (!$d->paid_currency)                                                { $open('deposit', $d->id, 'missing_currency', 'No payment currency was recorded for this approved deposit.'); continue; }
                if (strtoupper($d->paid_currency) !== 'USD') {
                    if (!$d->fx_rate || (float) $d->fx_rate <= 0)                      $open('deposit', $d->id, 'missing_rate', "{$d->paid_currency} payment with no recorded exchange rate.");
                    if (mb_strlen(trim((string) $d->rate_source)) < 3)                 $open('deposit', $d->id, 'missing_rate_source', "{$d->paid_currency} payment with no recorded rate source.");
                    if ($d->fx_rate && $d->paid_amount && $d->approved_amount) {
                        $calc = Decimal::mulHalfUp(Decimal::norm($d->paid_amount), Decimal::norm($d->fx_rate), 2);
                        if (abs((float) $calc - (float) $d->approved_amount) > 0.05)   $open('deposit', $d->id, 'inconsistent_amount', "Paid amount × rate ({$calc}) does not match the approved USD amount ({$d->approved_amount}).");
                    }
                }
                if (!$d->payment_verified_at)                                          $open('deposit', $d->id, 'no_valuation', 'Approved without recorded staff payment verification.');
            }
        });

        InvestmentAccount::where('status', '!=', 'cancelled')->orderBy('id')->chunkById(500, function ($rows) use ($open) {
            $have = UsdValuation::where('valuable_type', 'investment')->whereIn('valuable_id', $rows->pluck('id'))->pluck('valuable_id')->flip();
            foreach ($rows as $i) if (!isset($have[$i->id])) $open('investment', $i->id, 'no_valuation', 'No verified USD valuation is recorded for this position.');
        });

        return $counts;
    }

    /**
     * @param string $outcome  adjusted | verified_as_recorded | unresolved
     * @param array{currency?: string, rate?: mixed, rate_source?: ?string, rate_date?: ?string}|null $correction
     */
    public function resolve(FxReconciliationItem $item, string $outcome, string $evidence, User $actor, ?array $correction = null): FxReconciliationItem
    {
        if (!in_array($outcome, ['adjusted', 'verified_as_recorded', 'unresolved'], true)) throw ValidationException::withMessages(['outcome' => ['Unknown outcome.']]);
        if (mb_strlen(trim($evidence)) < 10) throw ValidationException::withMessages(['evidence' => ['Record the supporting evidence used to resolve this item (at least 10 characters).']]);

        return DB::transaction(function () use ($item, $outcome, $evidence, $actor, $correction) {
            $it = FxReconciliationItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($it->status === 'resolved') throw ValidationException::withMessages(['status' => ['This item is already resolved.']]);
            $before = ['status' => $it->status];
            $snapshotId = null;

            if ($outcome === 'adjusted') {
                if (!$correction || empty($correction['currency'])) throw ValidationException::withMessages(['correction' => ['An adjustment needs the corrected currency and conversion details.']]);
                if ($it->reason_code === 'crypto_unapproved' && $this->cryptoValuationBlocked()) {
                    throw ValidationException::withMessages(['correction' => ['Cryptocurrency valuation is not approved for rank eligibility, so it cannot be resolved by adjustment.']]);
                }
                $previous = FxConversionSnapshot::where('subject_type', $it->subject_type)->where('subject_id', $it->subject_id)->orderByDesc('id')->first();
                $v = app(UsdValuationService::class)->record($it->subject_type, (int) $it->subject_id, strtoupper($correction['currency']), $correction['rate'] ?? null,
                    $correction['rate_source'] ?? null, $correction['rate_date'] ?? null, 'Reconciliation: ' . $evidence, $actor, true, true, 'adjustment');
                $snapshotId = $this->snapshotFromValuation($v, 'reference_rate_manual', $actor, $evidence, $previous?->id, $it->id, $previous?->rate)?->id;
            } elseif ($outcome === 'verified_as_recorded') {
                $v = UsdValuation::where('valuable_type', $it->subject_type)->where('valuable_id', $it->subject_id)->orderByDesc('id')->first();
                if (!$v) throw ValidationException::withMessages(['outcome' => ['There is no verified valuation to confirm. Record an adjustment instead.']]);
                $snapshotId = $this->snapshotFromValuation($v, 'reference_rate_manual', $actor, null, null, $it->id)?->id;
            }

            $it->forceFill(['status' => $outcome === 'unresolved' ? 'unresolved' : 'resolved', 'evidence' => trim($evidence), 'resolved_by' => $actor->id,
                'resolved_by_name' => $this->audit->actorName($actor), 'resolved_at' => now(), 'snapshot_id' => $snapshotId])->save();
            $this->audit->audit('reconciliation', $it->id, "reconciliation_{$outcome}", $actor, $before, ['status' => $it->status, 'snapshot_id' => $snapshotId, 'subject' => "{$it->subject_type}#{$it->subject_id}"], $evidence);

            return $it->refresh();
        });
    }

    // ── immutable conversion snapshots (§4B) ────────────────────────────────────────────────────────────

    /** Snapshot of the conversion staff recorded and verified on a deposit. Uses ONLY the recorded values. Idempotent. */
    public function snapshotFromDeposit(Deposit $d, User $actor): ?FxConversionSnapshot
    {
        if (!$this->available() || !$d->payment_verified_at || !$d->paid_currency) return null;
        if (FxConversionSnapshot::where('subject_type', 'deposit')->where('subject_id', $d->id)->where('status', 'verified')->exists()) return null;

        $usd  = strtoupper($d->paid_currency) === 'USD';
        $rate = $usd ? '1' : Decimal::norm((float) $d->fx_rate);
        if (!$usd && (!$d->fx_rate || (float) $d->fx_rate <= 0)) return null;     // nothing is invented: no recorded rate, no snapshot

        $internal = Decimal::mul(Decimal::norm((float) $d->paid_amount), $rate);
        return FxConversionSnapshot::create([
            'subject_type' => 'deposit', 'subject_id' => $d->id, 'status' => 'verified', 'original_currency' => strtoupper($d->paid_currency),
            'original_amount' => (float) $d->paid_amount, 'pair' => strtoupper($d->paid_currency) . '/USD', 'rate' => $usd ? 1 : (float) $d->fx_rate,
            'rate_source' => $usd ? null : $d->rate_source, 'rate_timestamp' => $d->rate_date ? Carbon::parse($d->rate_date)->startOfDay() : null,
            'converted_at' => $d->payment_verified_at, 'conversion_method' => $usd ? 'usd_native' : 'reference_rate_manual',
            'internal_usd' => $internal, 'posted_usd' => $d->approved_amount !== null ? (float) $d->approved_amount : (float) Decimal::roundHalfUp($internal, 2),
            'rounding_method' => 'half_up_2dp', 'supporting_reference' => $d->external_reference, 'verified_by' => $d->payment_verified_by,
            'verified_by_name' => $actor->name ?? null, 'created_at' => now(),
        ]);
    }

    /** Snapshot of a verified valuation. A correction passes the snapshot it supersedes, the reason, and the original rate. */
    public function snapshotFromValuation(UsdValuation $v, string $method, User $actor, ?string $adjustmentReason = null, ?int $supersedesId = null, ?int $itemId = null, mixed $previousRate = null): ?FxConversionSnapshot
    {
        if (!$this->available()) return null;
        $internal = Decimal::mul(Decimal::norm((float) $v->original_amount), Decimal::norm((float) $v->fx_rate));

        return FxConversionSnapshot::create([
            'subject_type' => $v->valuable_type, 'subject_id' => $v->valuable_id, 'status' => 'verified', 'original_currency' => $v->currency,
            'original_amount' => (float) $v->original_amount, 'pair' => $v->currency . '/USD', 'rate' => (float) $v->fx_rate, 'rate_source' => $v->rate_source,
            'rate_timestamp' => $v->rate_date ? Carbon::parse($v->rate_date)->startOfDay() : null, 'converted_at' => $v->created_at ?? now(),
            'conversion_method' => $v->currency === 'USD' ? 'usd_native' : $method, 'internal_usd' => $internal, 'posted_usd' => (float) $v->usd_amount,
            'rounding_method' => 'half_up_2dp', 'supporting_reference' => $v->note, 'verified_by' => $actor->id, 'verified_by_name' => $actor->name ?? null,
            'supersedes_id' => $supersedesId, 'previous_rate' => $previousRate, 'adjustment_reason' => $adjustmentReason, 'reconciliation_item_id' => $itemId, 'created_at' => now(),
        ]);
    }

    // ── freshness (§4C) ─────────────────────────────────────────────────────────────────────────────────

    /** True/false only when a maximum rate age has been APPROVED for the currency; null = cannot be assessed (nothing is assumed). */
    public function isStale(string $currency, ?Carbon $rateAt, ?Carbon $asOf = null): ?bool
    {
        if (!$rateAt || !LedgerReaders::has('fx_approved_sources')) return null;
        $asOf ??= now();
        $src = FxApprovedSource::where('currency', strtoupper($currency))->whereNotNull('max_age_hours')
            ->whereDate('effective_from', '<=', $asOf->toDateString())->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $asOf->toDateString()))
            ->orderByDesc('effective_from')->first();
        if (!$src) return null;
        return $rateAt->diffInHours($asOf, true) > (int) $src->max_age_hours;
    }
}
