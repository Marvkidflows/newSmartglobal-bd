<?php
// LOCATION: app/Services/Rank/CoreRequirements.php
//
// The MANDATORY core requirement of every rank, exactly as written in the official SSI Investor Rank & Badge
// Management Technical Requirements: a minimum investment amount (USD) AND a minimum number of completed
// investment cycles. Both are required for every rank. A rule that does not contain BOTH, with the specified
// minimums, can never award a rank: it cannot be activated and, if one is already stored, the engine ignores it.
//
// The five ADDITIONAL criteria (§3) are NOT core and are never read here; they need written Management approval.
// Nothing here is a policy decision: the figures live in config/ranks.php ('core_requirements').

namespace App\Services\Rank;

use App\Models\RankCriterion;

class CoreRequirements
{
    public const AMOUNT_FIELD = 'total_invested';
    public const CYCLES_FIELD = 'completed_investment_count';

    public function enabled(): bool
    {
        return (bool) config('ranks.enforce_core_requirements', true);
    }

    /** @return array{min_amount: float, min_cycles: int, band_max: ?float}|null null = a rank the specification does not define */
    public function specFor(string $rankKey): ?array
    {
        return config("ranks.core_requirements.$rankKey");
    }

    /** Does a STORED criterion contain exactly the mandatory core requirement? (Always true when enforcement is off.) */
    public function conforms(RankCriterion $criterion): bool
    {
        if (!$this->enabled()) return true;

        $criterion->loadMissing(['rank', 'conditions']);
        $rule = $criterion->toRule();

        return $this->problems((string) $criterion->rank?->key, (string) $rule['logic'], $rule['conditions']) === [];
    }

    /**
     * @param  array<int, array{field:string, operator:string, value:mixed, group_no?:int}>  $conditions
     * @return string[] plain-language reasons the rule does not match the specification (empty = conforms)
     */
    public function problems(string $rankKey, string $logic, array $conditions): array
    {
        $spec = $this->specFor($rankKey);
        if (!$spec) return [];

        $name = ucfirst($rankKey);
        $out  = [];

        if ($logic === 'any' || ($logic === 'grouped' && collect($conditions)->pluck('group_no')->unique()->count() > 1)) {
            $out[] = "$name: both the investment amount AND the completed-cycle requirement are mandatory; the rule must use \"ALL conditions\", not \"any\".";
        }

        $amount = collect($conditions)->where('field', self::AMOUNT_FIELD)->values();
        $cycles = collect($conditions)->where('field', self::CYCLES_FIELD)->values();
        $other  = collect($conditions)->reject(fn ($c) => in_array($c['field'], [self::AMOUNT_FIELD, self::CYCLES_FIELD], true));

        $minAmount = $this->fmt($spec['min_amount']);

        // Amount: "at least $min" (or the specification's band, min to max). Anything lower grants a rank below the minimum;
        // anything higher withholds a rank from investors who meet the specification.
        if ($amount->count() !== 1) {
            $out[] = "$name: requires exactly one investment-amount condition (at least \${$minAmount} USD).";
        } else {
            $c = $amount->first();
            if ($c['operator'] === '>=') {
                $given = (float) $c['value'];
            } elseif ($c['operator'] === 'between' && is_array($c['value']) && count($c['value']) === 2) {
                $given = (float) $c['value'][0];
                $bandMax = $spec['band_max'] ?? null;
                if ($bandMax !== null && (float) $c['value'][1] !== (float) $bandMax) {
                    $out[] = "$name: the amount band must end at \${$this->fmt($bandMax)} as specified.";
                }
                if ($bandMax === null) {
                    $out[] = "$name: has no upper amount limit in the specification; use \"at least \${$minAmount}\".";
                }
            } else {
                $given = null;
                $out[] = "$name: the amount must be \"at least \${$minAmount}\" USD.";
            }
            if ($given !== null && abs($given - (float) $spec['min_amount']) > 0.00001) {
                $out[] = "$name: the specified minimum investment is \${$minAmount} USD; this rule says \${$this->fmt($given)}.";
            }
        }

        // Completed cycles: must be present and ≥ the specified number (0 would make the requirement meaningless).
        if ($cycles->count() !== 1) {
            $out[] = "$name: requires exactly one completed-cycle condition (at least {$spec['min_cycles']}).";
        } else {
            $c = $cycles->first();
            if ($c['operator'] !== '>=') {
                $out[] = "$name: completed cycles must be \"at least {$spec['min_cycles']}\".";
            } elseif ((int) $c['value'] !== (int) $spec['min_cycles']) {
                $out[] = "$name: the specified minimum is {$spec['min_cycles']} completed cycle(s); this rule says " . (int) $c['value'] . '.';
            }
        }

        foreach ($other as $c) {
            $out[] = "$name: '{$c['field']}' is not a core requirement. Additional criteria need written Management approval and are configured under Additional criteria.";
        }

        return array_values(array_unique($out));
    }

    protected function fmt(float|int $n): string
    {
        return number_format((float) $n, 0, '.', ',');
    }
}
