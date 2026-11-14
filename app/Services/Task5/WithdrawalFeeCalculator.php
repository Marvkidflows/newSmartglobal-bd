<?php
// Task 5: pure, decimal-safe withdrawal-fee calculation. No floats, no database writes.
//
// RULES (the numbers are Management's to set; these are only the mechanics):
//   fixed       fee = fixed_amount                                  (applied ONCE)
//   percentage  fee = gross x percentage / 100                      (base = the GROSS requested amount; half-up to 2 dp, same rounding as the rest of the system)
//   combined    fee = fixed_amount + gross x percentage / 100       (fixed once + percentage of gross, rounded once at the end)
//   min_fee / max_fee clamp a computed non-zero fee when configured.
//   A fee is charged ONLY when the policy is active AND approved AND effective AND its type is not 'none'; otherwise the fee is exactly 0.00.
//   The fee may never reach or exceed the gross amount: the request is refused rather than silently altered (net must stay > 0).
//   Gross must be a positive amount with at most 2 decimals.
namespace App\Services\Task5;

use App\Models\WithdrawalFeePolicy;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

class WithdrawalFeeCalculator
{
    /** @return array{gross:string,fee:string,net:string,currency:string,fee_type:string,policy_id:?int,applied:bool,percentage:?string,fixed_amount:?string,explanation:string,steps:array} */
    public function calculate(mixed $grossInput, ?WithdrawalFeePolicy $policy): array
    {
        $raw = is_string($grossInput) ? trim($grossInput) : $grossInput;
        if (is_bool($raw) || $raw === null || $raw === '' || !is_numeric($raw)) $this->fail('Enter a valid withdrawal amount.');
        $gross = Decimal::fromInput($raw);
        if (str_starts_with($gross, '-') || Decimal::lte($gross, '0')) $this->fail('The withdrawal amount must be greater than zero.');
        if (!Decimal::eq($gross, Decimal::roundHalfUp($gross, 2))) $this->fail('The withdrawal amount can have at most 2 decimal places.');
        $gross = Decimal::roundHalfUp($gross, 2);

        $currency = $policy?->currency ?: (string) config('ssi_task5.withdrawal_fee.default_currency', 'USD');
        $base = ['gross' => $gross, 'currency' => $currency, 'fee_type' => 'none', 'policy_id' => $policy?->id, 'percentage' => null, 'fixed_amount' => null];

        if (!$policy || !$policy->is_active || !$policy->policy_approved || $policy->fee_type === 'none' || $policy->effective_from?->isFuture()) {
            return $base + ['fee' => '0.00', 'net' => $gross, 'applied' => false, 'explanation' => 'No withdrawal fee applies to this request.',
                'steps' => ['policy' => $policy ? 'inactive_or_unapproved' : 'none']];
        }

        $fixed = $policy->fixed_amount !== null ? Decimal::roundHalfUp(Decimal::fromInput($policy->fixed_amount), 2) : '0.00';
        $pct   = $policy->percentage !== null ? Decimal::fromInput($policy->percentage) : '0';
        $steps = ['fee_type' => $policy->fee_type];

        $fee = match ($policy->fee_type) {
            'fixed'      => $this->fixedPart($fixed, $steps),
            'percentage' => $this->pctPart($gross, $pct, $steps),
            'combined'   => Decimal::roundHalfUp(Decimal::add($this->fixedPart($fixed, $steps), $this->pctPart($gross, $pct, $steps, false)), 2),
            default      => $this->fail('Unsupported fee type.'),
        };

        if (Decimal::gt($fee, '0')) {
            if ($policy->min_fee !== null && Decimal::lt($fee, Decimal::fromInput($policy->min_fee))) { $steps['min_applied'] = (string) $policy->min_fee; $fee = Decimal::roundHalfUp(Decimal::fromInput($policy->min_fee), 2); }
            if ($policy->max_fee !== null && Decimal::gt($fee, Decimal::fromInput($policy->max_fee))) { $steps['max_applied'] = (string) $policy->max_fee; $fee = Decimal::roundHalfUp(Decimal::fromInput($policy->max_fee), 2); }
        }
        $steps['fee'] = $fee;

        if (Decimal::gte($fee, $gross)) $this->fail('The withdrawal fee would equal or exceed the amount requested. Please request a larger amount.');
        $net = Decimal::sub($gross, $fee);

        // array_merge (later keys WIN). The previous `$base + [...]` kept the base values for fee_type / fixed_amount / percentage, so a charged fee was
        // quoted and frozen as fee_type 'none' with null fixed/percentage (the fee amount itself was right).
        return array_merge($base, ['fee_type' => $policy->fee_type, 'fixed_amount' => in_array($policy->fee_type, ['fixed', 'combined'], true) ? $fixed : null,
            'percentage' => in_array($policy->fee_type, ['percentage', 'combined'], true) ? Decimal::norm($pct) : null,
            'fee' => $fee, 'net' => $net, 'applied' => Decimal::gt($fee, '0'), 'steps' => $steps, 'explanation' => $this->explain($policy->fee_type, $fixed, $pct, $currency)]);
    }

    protected function fixedPart(string $fixed, array &$steps): string { $steps['fixed'] = $fixed; return $fixed; }

    protected function pctPart(string $gross, string $pct, array &$steps, bool $round = true): string
    {
        $steps['percentage'] = Decimal::norm($pct); $steps['base'] = $gross;
        $exact = Decimal::mul(Decimal::mul($gross, $pct), '0.01');
        $steps['percentage_exact'] = $exact;
        return $round ? Decimal::roundHalfUp($exact, 2) : $exact;     // combined: round once, after adding the fixed part
    }

    protected function explain(string $type, string $fixed, string $pct, string $cur): string
    {
        $p = Decimal::norm($pct);
        return match ($type) {
            'fixed'      => "A fixed withdrawal fee of {$fixed} {$cur} applies.",
            'percentage' => "A withdrawal fee of {$p}% of the requested amount applies.",
            'combined'   => "A withdrawal fee of {$fixed} {$cur} plus {$p}% of the requested amount applies.",
            default      => '',
        };
    }

    protected function fail(string $m): never { throw ValidationException::withMessages(['amount' => [$m]]); }
}
