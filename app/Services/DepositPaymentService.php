<?php
// LOCATION: app/Services/DepositPaymentService.php
//
// Records what was ACTUALLY paid for a deposit that was arranged through the Telegram representative (or any other
// channel) — amount paid, currency, the recorded exchange rate (+ source + date), the payer's transfer reference —
// and the USD amount SSI personnel approve for crediting. Telegram only coordinates; nothing here reads a Telegram
// message, and nothing here approves a deposit: the deposit stays pending/hold until the EXISTING approve action
// (same permissions) is used. Writes only the deposit's own payment fields (+ `amount`, the figure approval credits)
// while it is still pending/on hold, and a Financial audit entry in the same transaction. Never touches a balance.

namespace App\Services;

use App\Models\Deposit;
use App\Models\InvestmentAccount;
use App\Models\User;
use App\Services\Rank\CurrencyCatalog;
use App\Services\Rank\UsdValuationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DepositPaymentService
{
    public const CHANNELS = ['telegram', 'app', 'other'];

    public function __construct(
        protected FinancialAuditService $audit,
        protected CurrencyCatalog $currencies,
        protected UsdValuationService $valuations,
    ) {}

    /** The investor's own, UNVERIFIED statement of how they paid (kept apart from staff-verified details). */
    public function declare(Deposit $deposit, array $d): void
    {
        if (!in_array($deposit->status, ['pending', 'hold'], true) || $deposit->payment_verified_at) return;

        $fill = [];
        if (isset($d['paid_currency'])) $fill['paid_currency'] = strtoupper(trim($d['paid_currency']));
        if (isset($d['paid_amount']))   $fill['paid_amount']   = round((float) $d['paid_amount'], 2);
        if (isset($d['external_reference'])) $fill['external_reference'] = trim($d['external_reference']);
        if (isset($d['funding_channel']))    $fill['funding_channel']    = $d['funding_channel'];
        if ($fill) $deposit->update($fill);
    }

    /**
     * @param array{paid_amount: mixed, paid_currency: string, rate?: mixed, rate_source?: ?string, rate_date?: ?string,
     *              external_reference?: ?string, funding_channel?: ?string, approved_amount?: mixed, note: string} $d
     */
    public function record(Deposit $deposit, User $actor, array $d, bool $confirmDistinct = false, bool $acknowledgeRateWarnings = false, bool $acknowledgeAmountDifference = false): Deposit
    {
        $currency = strtoupper(trim((string) ($d['paid_currency'] ?? '')));
        $isUsd    = $currency === UsdValuationService::RANKING_CURRENCY;
        $paid     = $d['paid_amount'] ?? null;
        $ref      = isset($d['external_reference']) ? trim((string) $d['external_reference']) : '';
        $note     = trim((string) ($d['note'] ?? ''));
        $channel  = $d['funding_channel'] ?? null;
        $errors   = [];

        if (!is_numeric($paid) || (float) $paid <= 0 || (float) $paid > 1e12) $errors['paid_amount'][] = 'Enter the amount actually paid (greater than zero).';
        if (!$this->currencies->isSupported($currency)) $errors['paid_currency'][] = 'Choose a supported currency code for what was paid. "' . $currency . '" is not recognised.';
        if (mb_strlen($note) < 5) $errors['note'][] = 'A note of at least 5 characters is required (what you checked and against what).';
        if ($channel !== null && !in_array($channel, self::CHANNELS, true)) $errors['funding_channel'][] = 'Channel must be telegram, app or other.';

        $rate = $d['rate'] ?? null;
        if (!$isUsd) {
            if (!is_numeric($rate) || (float) $rate <= 0 || (float) $rate > 1e9) $errors['rate'][] = 'A positive exchange rate to USD is required for a non-USD payment.';
            if (mb_strlen(trim((string) ($d['rate_source'] ?? ''))) < 3) $errors['rate_source'][] = 'State where the exchange rate came from.';
            if (empty($d['rate_date']) || !strtotime((string) $d['rate_date'])) $errors['rate_date'][] = 'State the date the exchange rate applies to.';
            elseif (Carbon::parse($d['rate_date'])->startOfDay()->gt(Carbon::today())) $errors['rate_date'][] = 'The rate date cannot be in the future.';
        }
 
        if (!$isUsd) {
            if (empty($d['payment_date']) || !strtotime((string) $d['payment_date'])) $errors['payment_date'][] = 'State the date the payment was received.';
            elseif (Carbon::parse($d['payment_date'])->startOfDay()->gt(Carbon::today())) $errors['payment_date'][] = 'The payment date cannot be in the future.';
        } elseif (!empty($d['payment_date']) && (!strtotime((string) $d['payment_date']) || Carbon::parse($d['payment_date'])->startOfDay()->gt(Carbon::today()))) {
            $errors['payment_date'][] = 'The payment date is not valid.';
        }
        if (isset($d['approved_amount']) && $d['approved_amount'] !== '' && $d['approved_amount'] !== null
            && (!is_numeric($d['approved_amount']) || (float) $d['approved_amount'] <= 0 || (float) $d['approved_amount'] > 1e12)) {
            $errors['approved_amount'][] = 'The approved amount must be greater than zero.';
        }
        if ($errors) throw ValidationException::withMessages($errors);

        return DB::transaction(function () use ($deposit, $actor, $d, $currency, $isUsd, $paid, $rate, $ref, $note, $channel, $confirmDistinct, $acknowledgeRateWarnings, $acknowledgeAmountDifference) {
            $locked = Deposit::where('id', $deposit->id)->lockForUpdate()->first();
            if (!$locked) throw ValidationException::withMessages(['deposit' => ['Deposit not found.']]);

            if (!in_array($locked->status, ['pending', 'hold'], true)) {
                throw ValidationException::withMessages(['deposit' => ['Payment details can only be recorded while the deposit is pending or on hold.']]);
            }

            // The same payer reference on another live deposit is the signature of one transfer being submitted twice.
            if ($ref !== '' && !$confirmDistinct) {
                $dup = Deposit::query()->where('id', '!=', $locked->id)->where('status', '!=', 'rejected')
                    ->whereRaw('LOWER(external_reference) = ?', [mb_strtolower($ref)])->orderBy('id')->value('id');
                if ($dup) {
                    throw ValidationException::withMessages(['duplicate' => ["Payment reference \"{$ref}\" is already recorded on deposit #{$dup}. If this is a separate genuine payment, confirm that and save again."]]);
                }
            }

            $fx = $isUsd ? 1.0 : (float) $rate;
            $warnings = $isUsd ? [] : $this->valuations->rateWarnings($currency, $fx, Carbon::parse($d['rate_date']), !empty($d['payment_date']) ? Carbon::parse($d['payment_date']) : $locked->created_at, null, null, (int) $locked->id);
            if ($warnings && !$acknowledgeRateWarnings) throw ValidationException::withMessages(['rate_warnings' => $warnings]);

            $paidAmount = round((float) $paid, 2);
            $computed   = round($paidAmount * $fx, 2);
            $approved   = (isset($d['approved_amount']) && $d['approved_amount'] !== '' && $d['approved_amount'] !== null) ? round((float) $d['approved_amount'], 2) : $computed;

            // The approved USD value must follow from what was paid and the recorded rate; a larger difference is only
            // allowed when staff knowingly acknowledge it (it is stored and audited).
            $diffPct = $computed > 0 ? abs($approved - $computed) / $computed * 100 : 100.0;
            $tolerance = (float) config('ranks.approved_amount_tolerance_percent', 1);
            $differs = $diffPct > $tolerance;
            if ($differs && !$acknowledgeAmountDifference) {
                throw ValidationException::withMessages(['amount_difference' => [sprintf(
                    'The approved amount %s USD differs by %s%% from %s %s × %s = %s USD (tolerance %s%%). Correct it, or confirm the difference is intentional (e.g. fees).',
                    number_format($approved, 2), number_format($diffPct, 1), number_format($paidAmount, 2), $currency, rtrim(rtrim(number_format($fx, 10, '.', ''), '0'), '.'), number_format($computed, 2), $tolerance)]]);
            }

            $before = [
                'amount' => (float) $locked->amount, 'paid_amount' => $locked->paid_amount !== null ? (float) $locked->paid_amount : null,
                'paid_currency' => $locked->paid_currency, 'verified' => (bool) $locked->payment_verified_at,
            ];

            // `amount` is what approval credits and opens the investment for. It becomes the staff-approved USD figure.
            $locked->update([
                'paid_amount' => $paidAmount, 'paid_currency' => $currency, 'fx_rate' => $fx,
                'rate_source' => $isUsd ? null : trim((string) $d['rate_source']), 'rate_date' => $isUsd ? null : Carbon::parse($d['rate_date'])->toDateString(),
                'approved_amount' => $approved, 'amount' => $approved,
                'external_reference' => $ref !== '' ? $ref : $locked->external_reference,
                'funding_channel' => $channel ?? $locked->funding_channel,
                'payment_note' => $note, 'payment_verified_by' => $actor->id, 'payment_verified_at' => now(),
                'payment_date' => !empty($d['payment_date']) ? Carbon::parse($d['payment_date'])->toDateString() : null,
                'amount_difference_acknowledged' => $differs,
            ]);

            $this->audit->record(
                $actor, 'deposit.payment_recorded', 'deposit', $locked->id, $locked->user_id, $before,
                ['paid_amount' => $paidAmount, 'paid_currency' => $currency, 'fx_rate' => $fx, 'rate_source' => $locked->rate_source,
                 'rate_date' => $locked->rate_date?->toDateString(), 'approved_amount' => $approved, 'computed_usd' => $computed,
                 'external_reference' => $locked->external_reference, 'funding_channel' => $locked->funding_channel,
                 'rate_warnings_acknowledged' => $warnings, 'distinct_confirmed' => $confirmDistinct && $ref !== '',
                 'payment_date' => $locked->payment_date?->toDateString(), 'amount_difference_acknowledged' => $differs],
                $note
            );

            return $locked->fresh();
        });
    }

    /**
     * Why this deposit may NOT be approved yet (empty = safe to approve). Approval credits the wallet and opens an
     * investment, so the actual payment must be established first: a staff-verified record of the amount paid, its
     * currency and — unless it is USD — the recorded rate, source and payment date, with the approved USD figure
     * following from them. Re-checked at approval time so a figure changed after verification cannot slip through.
     * @return string[]
     */
    public function approvalBlockers(Deposit $d): array
    {
        $why = [];
        if (!$d->payment_verified_at) {
            return [$d->paid_currency && $d->paid_currency !== 'USD'
                ? "The investor declared a {$d->paid_currency} payment. Record the amount paid, the exchange rate, its source and the payment date first."
                : 'The actual payment has not been verified. Record the amount and currency received (or confirm it was received in USD) before approving.'];
        }
        if (!$d->paid_currency || !$this->currencies->isSupported($d->paid_currency)) $why[] = 'The currency paid is not recorded or not supported.';
        if ((float) $d->paid_amount <= 0) $why[] = 'The amount paid is not recorded.';
        if ((float) $d->approved_amount <= 0) $why[] = 'No approved USD amount is recorded.';
        elseif (abs((float) $d->amount - (float) $d->approved_amount) > 0.004) $why[] = 'The deposit amount was changed after the payment was verified. Record the payment again.';

        if ($d->paid_currency && $d->paid_currency !== UsdValuationService::RANKING_CURRENCY) {
            if ((float) $d->fx_rate <= 0) $why[] = 'A positive exchange rate is required for a non-USD payment.';
            if (mb_strlen(trim((string) $d->rate_source)) < 3) $why[] = 'The exchange rate source is missing.';
            if (!$d->rate_date) $why[] = 'The exchange rate date is missing.';
            if (!$d->payment_date) $why[] = 'The payment date is missing.';
        }

        if (!$why && (float) $d->fx_rate > 0) {
            $computed = round((float) $d->paid_amount * (float) $d->fx_rate, 2);
            $pct = $computed > 0 ? abs((float) $d->approved_amount - $computed) / $computed * 100 : 100.0;
            if ($pct > (float) config('ranks.approved_amount_tolerance_percent', 1) && !$d->amount_difference_acknowledged) {
                $why[] = 'The approved amount does not follow from the amount paid and the recorded rate.';
            }
        }
        return $why;
    }

    /**
     * The existing "payment was received in USD" flow, made explicit: the approver attests (with a reason, recorded
     * under their name) that the amount on the deposit was received in USD. Only possible when no other currency was
     * declared — a declared non-USD payment must go through record().
     */
    public function attestUsd(Deposit $deposit, User $actor, string $reason): Deposit
    {
        if ($deposit->paid_currency && $deposit->paid_currency !== UsdValuationService::RANKING_CURRENCY) {
            throw ValidationException::withMessages(['paid_currency' => ["The investor declared a {$deposit->paid_currency} payment; record it with its exchange rate instead."]]);
        }
        return $this->record($deposit, $actor, [
            'paid_amount' => (float) $deposit->amount, 'paid_currency' => 'USD', 'note' => $reason,
            'payment_date' => now()->toDateString(), 'external_reference' => null,
        ], true);
    }

    /**
     * Called by the existing approve action, inside its transaction, AFTER the deposit is approved. Only when SSI
     * personnel have verified this deposit's payment details does it record the USD value of the deposit and of the
     * investment it opened (the wallet unit is USD by construction: `amount` is the staff-approved USD figure).
     * Anything else stays UNVERIFIED and is flagged for review — nothing is assumed. Never blocks the approval.
     */
    public function valueApproved(Deposit $deposit, ?InvestmentAccount $investment, User $actor): void
    {
        if (!$deposit->payment_verified_at || !$deposit->paid_currency) return;

        $how = sprintf('Staff-verified payment on deposit #%d: %s %s%s.', $deposit->id, number_format((float) $deposit->paid_amount, 2), $deposit->paid_currency,
            $deposit->paid_currency === 'USD' ? '' : sprintf(' @ %s (%s, %s)', rtrim(rtrim(number_format((float) $deposit->fx_rate, 10, '.', ''), '0'), '.'), $deposit->rate_source, $deposit->rate_date?->toDateString()));

        foreach ([['deposit', $deposit->id], ['investment', $investment?->id]] as [$type, $id]) {
            if (!$id) continue;
            try {
                $this->valuations->record($type, (int) $id, 'USD', null, null, null, $how, $actor, false);
            } catch (ValidationException $e) {
                // e.g. looks like a duplicate investment — leave it unverified; it appears in the review queue.
                Log::warning("Deposit #{$deposit->id}: {$type} #{$id} left unverified: " . collect($e->errors())->flatten()->first());
            }
        }
    }
}
