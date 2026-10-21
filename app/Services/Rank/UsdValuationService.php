<?php
// LOCATION: app/Services/Rank/UsdValuationService.php
//
// The ONLY way a record gets a USD value for ranking. A person (Financial team / Admin) states what currency
// the stored amount is in and, if it is not USD, the exchange rate and where it came from. The USD figure is
// computed here, server-side — never accepted from the client. Everything is append-only and also written to
// the existing Financial audit log. This service NEVER changes an investment, deposit or balance.

namespace App\Services\Rank;

use App\Jobs\EvaluateInvestorRankJob;
use App\Models\Deposit;
use App\Models\InvestmentAccount;
use App\Models\UsdValuation;
use App\Models\User;
use App\Services\FinancialAuditService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UsdValuationService
{
    public const RANKING_CURRENCY = 'USD';

    public function __construct(
        protected FinancialAuditService $audit,
        protected RankInvestmentLedger $ledger,
        protected CurrencyCatalog $currencies,
    ) {}

    public function record(
        string $type,
        int $recordId,
        string $currency,
        mixed $rate,
        ?string $rateSource,
        ?string $rateDate,
        string $note,
        User $actor,
        bool $confirmDistinct = false,
        bool $acknowledgeRateWarnings = false,
    ): UsdValuation {
        $type     = strtolower(trim($type));
        $currency = strtoupper(trim($currency));
        $isUsd    = $currency === self::RANKING_CURRENCY;
        $errors   = [];

        if (!in_array($type, UsdValuation::TYPES, true)) $errors['type'][] = 'Type must be investment or deposit.';
        if (!preg_match('/^[A-Z]{3,8}$/', $currency) || !$this->currencies->isSupported($currency)) {
            $errors['currency'][] = 'Choose a supported currency code for the stored amount (e.g. USD, NGN, EUR, GBP). "' . $currency . '" is not recognised.';
        }
        if (mb_strlen(trim($note)) < 5) $errors['note'][] = 'A note of at least 5 characters is required (what you verified and against what).';

        if (!$isUsd) {
            // A non-USD amount can only be ranked through a recorded, attributable exchange rate.
            if (!is_numeric($rate) || (float) $rate <= 0 || (float) $rate > 1e9) $errors['rate'][] = 'A positive exchange rate to USD is required for a non-USD amount.';
            if (mb_strlen(trim((string) $rateSource)) < 3) $errors['rate_source'][] = 'State where the exchange rate came from.';
            if (!$rateDate || !strtotime($rateDate)) $errors['rate_date'][] = 'State the date the exchange rate applies to.';
            elseif (Carbon::parse($rateDate)->startOfDay()->gt(Carbon::today())) $errors['rate_date'][] = 'The rate date cannot be in the future.';
        }

        if ($errors) throw ValidationException::withMessages($errors);

        $valuation = DB::transaction(function () use ($type, $recordId, $currency, $isUsd, $rate, $rateSource, $rateDate, $note, $actor, $confirmDistinct, $acknowledgeRateWarnings) {
            $record = $type === 'investment'
                ? InvestmentAccount::query()->whereKey($recordId)->lockForUpdate()->first()
                : Deposit::query()->whereKey($recordId)->lockForUpdate()->first();

            if (!$record) throw ValidationException::withMessages(['record' => ['Record not found.']]);

            if ($type === 'investment' && $record->status === 'cancelled') {
                throw ValidationException::withMessages(['record' => ['Cancelled investments are excluded from ranking and are not valued.']]);
            }
            if ($type === 'deposit' && $record->status !== 'approved') {
                throw ValidationException::withMessages(['record' => ['Only approved deposits are valued.']]);
            }

            if ($type === 'investment' && !$confirmDistinct && ($dupOf = $this->ledger->duplicateOf($record))) {
                throw ValidationException::withMessages(['duplicate' => [
                    "This looks like a duplicate of investment #{$dupOf} (same investor, plan, amount and start date, created within "
                    . (int) config('ranks.duplicate_window_seconds', 60) . ' seconds). It is excluded from ranking. If it is a separate genuine investment, confirm that and verify it again.',
                ]]);
            }

            $warnings = $isUsd ? [] : $this->rateWarnings($currency, (float) $rate, Carbon::parse($rateDate), $record->created_at, $type, (int) $record->id);
            if ($warnings && !$acknowledgeRateWarnings) {
                throw ValidationException::withMessages(['rate_warnings' => $warnings]);
            }

            $original = round((float) $record->amount, 2);
            $fx       = $isUsd ? 1.0 : (float) $rate;
            $usd      = round($original * $fx, 2);

            $previous = UsdValuation::query()->where('valuable_type', $type)->where('valuable_id', $record->id)->orderByDesc('id')->first();

            $valuation = UsdValuation::create([
                'valuable_type' => $type, 'valuable_id' => $record->id, 'investor_id' => $record->user_id,
                'currency' => $currency, 'original_amount' => $original, 'fx_rate' => $fx, 'usd_amount' => $usd,
                'rate_source' => $isUsd ? null : trim((string) $rateSource), 'rate_date' => $isUsd ? null : Carbon::parse($rateDate)->toDateString(),
                'distinct_confirmed' => $confirmDistinct, 'note' => trim($note),
                'verified_by' => $actor->id, 'verified_by_name' => $actor->name ?? $actor->full_name ?? 'Unknown',
                'created_at' => now(),
            ]);

            // The existing, immutable Financial audit trail (same transaction as the valuation).
            $this->audit->record(
                $actor, 'currency.verified', $type, $record->id, $record->user_id,
                $previous ? ['currency' => $previous->currency, 'original_amount' => (float) $previous->original_amount, 'usd_amount' => (float) $previous->usd_amount] : null,
                ['currency' => $currency, 'original_amount' => $original, 'fx_rate' => $fx, 'usd_amount' => $usd,
                 'rate_source' => $valuation->rate_source, 'rate_date' => $valuation->rate_date?->toDateString(), 'distinct_confirmed' => $confirmDistinct,
                 'rate_warnings_acknowledged' => $warnings],
                trim($note)
            );

            return $valuation;
        });

        // A new USD figure can change eligibility: re-evaluate just this investor (queued, de-duplicated).
        rescue(fn () => EvaluateInvestorRankJob::dispatch((int) $valuation->investor_id, 'currency_verified')->afterCommit(), null, true);

        return $valuation;
    }

    /**
     * Human-readable warnings about a typed rate. They block the save until the person acknowledges them, and the
     * acknowledged text is stored in the Financial audit entry. A rate is never rejected or altered automatically.
     * @return string[]
     */
    public function rateWarnings(string $currency, float $rate, Carbon $rateDate, mixed $recordCreatedAt, ?string $type = null, ?int $recordId = null, ?int $excludeDepositId = null): array
    {
        $out = [];

        $last = UsdValuation::query()->where('currency', $currency)->where('fx_rate', '>', 0)
            ->when($type && $recordId, fn ($q) => $q->where(fn ($w) => $w->where('valuable_type', '!=', $type)->orWhere('valuable_id', '!=', $recordId)))
            ->orderByDesc('id')->first();
        // Rates staff recorded on deposits (Telegram-arranged payments) count as recorded rates too; the newest one wins.
        $dep = \App\Models\Deposit::query()->where('paid_currency', $currency)->whereNotNull('payment_verified_at')->where('fx_rate', '>', 0)
            ->when($excludeDepositId, fn ($q) => $q->where('id', '!=', $excludeDepositId))->orderByDesc('payment_verified_at')->orderByDesc('id')->first();
        if ($dep && (!$last || $dep->payment_verified_at->gt($last->created_at))) {
            $last = new UsdValuation(['fx_rate' => $dep->fx_rate, 'rate_date' => $dep->rate_date]);
        }
        $pct = (float) config('ranks.rate_deviation_percent', 20);
        if ($last && $pct > 0) {
            $ref = (float) $last->fx_rate;
            $dev = abs($rate - $ref) / $ref * 100;
            if ($dev > $pct) {
                $out[] = sprintf('The rate %s differs by %s%% from the most recent recorded %s rate (%s%s). Check it is not inverted or mistyped.',
                    rtrim(rtrim(number_format($rate, 10, '.', ''), '0'), '.'), number_format($dev, 1), $currency,
                    rtrim(rtrim(number_format($ref, 10, '.', ''), '0'), '.'), $last->rate_date ? ' dated ' . $last->rate_date->toDateString() : '');
            }
        }

        $tol = (int) config('ranks.rate_date_tolerance_days', 7);
        if ($recordCreatedAt && $tol >= 0) {
            $days = (int) abs(Carbon::parse($recordCreatedAt)->startOfDay()->diffInDays($rateDate->copy()->startOfDay(), true));
            if ($days > $tol) $out[] = "The rate date is {$days} days from the date this record was created (tolerance {$tol} days). Confirm the rate applies to the transfer date.";
        }

        return $out;
    }

    /**
     * Recently recorded rates per currency, newest first, so the person can reuse a rate they (or a colleague) already
     * recorded instead of retyping it. Informational: reusing one still means typing it and its source/date.
     * @return array<int, array{rate: float, rate_source: ?string, rate_date: ?string, recorded_at: ?string}>
     */
    public function recentRates(string $currency, int $limit = 5): array
    {
        return UsdValuation::query()->where('currency', strtoupper(trim($currency)))->where('fx_rate', '>', 0)
            ->where('currency', '!=', self::RANKING_CURRENCY)
            ->orderByDesc('id')->limit(100)->get()
            ->unique(fn ($v) => (string) $v->fx_rate . '|' . $v->rate_source . '|' . $v->rate_date?->toDateString())
            ->take($limit)
            ->map(fn ($v) => ['rate' => (float) $v->fx_rate, 'rate_source' => $v->rate_source, 'rate_date' => $v->rate_date?->toDateString(), 'recorded_at' => $v->created_at?->toIso8601String()])
            ->values()->all();
    }

    /**
     * Counts of what still needs a person's attention (all non-cancelled investments / approved deposits).
     * @return array{investments: array{unverified: int, stale: int, duplicate_suspects: int, total: int},
     *               deposits: array{unverified: int, stale: int, total: int}, needs_review: int}
     */
    public function summary(): array
    {
        $inv = $this->countBy('investment_accounts', 'investment', fn ($q) => $q->where('t.status', '!=', 'cancelled'));
        $dep = $this->countBy('deposits', 'deposit', fn ($q) => $q->where('t.status', '=', 'approved'));

        $dups = $this->duplicateSuspectIds();

        return [
            'investments' => ['unverified' => $inv['unverified'], 'stale' => $inv['stale'], 'duplicate_suspects' => count($dups), 'total' => $inv['total']],
            'deposits'    => ['unverified' => $dep['unverified'], 'stale' => $dep['stale'], 'total' => $dep['total']],
            'needs_review' => $inv['unverified'] + $inv['stale'] + count($dups) + $dep['unverified'] + $dep['stale'],
        ];
    }

    /** @return int[] ids of non-cancelled investments that look like duplicates and are not confirmed distinct */
    public function duplicateSuspectIds(?int $userId = null): array
    {
        $users = DB::table('investment_accounts')->where('status', '!=', 'cancelled')
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->select('user_id', 'investment_plan_id', 'amount')
            ->groupBy('user_id', 'investment_plan_id', 'amount')->havingRaw('COUNT(*) > 1')->pluck('user_id')->unique()->all();

        $ids = [];
        foreach (array_chunk($users, 200) as $chunk) {
            foreach ($this->ledger->forUsers($chunk) as $facts) $ids = array_merge($ids, $facts['duplicates']);
        }
        return $ids;
    }

    protected function countBy(string $table, string $type, callable $scope): array
    {
        $base = fn () => tap(DB::table("$table as t"), $scope)
            ->leftJoin('usd_valuations as v', function ($j) use ($type) {
                $j->on('v.valuable_id', '=', 't.id')->where('v.valuable_type', '=', $type)
                  ->whereIn('v.id', function ($q) use ($type) {
                      $q->from('usd_valuations')->selectRaw('MAX(id)')->where('valuable_type', $type)->groupBy('valuable_id');
                  });
            });

        return [
            'total'      => (clone $base())->count(),
            'unverified' => (clone $base())->whereNull('v.id')->count(),
            'stale'      => (clone $base())->whereNotNull('v.id')->whereColumn('v.original_amount', '!=', 't.amount')->count(),
        ];
    }
}
