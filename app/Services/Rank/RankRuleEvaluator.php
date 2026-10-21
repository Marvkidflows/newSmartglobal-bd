<?php
// LOCATION: app/Services/Rank/RankRuleEvaluator.php
//
// Pure, side-effect-free evaluation of a rule against already-resolved values.
// A rule is: ['logic' => all|any|grouped, 'conditions' => [[group_no, field, operator, value], …]]
// Nothing here executes admin-supplied code or SQL — values are only ever
// compared with the fixed operator set below.

namespace App\Services\Rank;

class RankRuleEvaluator
{
    private const EPSILON = 0.000001;

    public function __construct(protected RankFieldRegistry $registry) {}

    /**
     * @param array $rule   ['logic' => string, 'conditions' => array]
     * @param array $values [fieldKey => resolved value]
     * @return array{satisfied: bool, logic: string, conditions: array}
     */
    public function evaluate(array $rule, array $values): array
    {
        $logic   = $rule['logic'] ?? 'all';
        $results = [];

        foreach ($rule['conditions'] ?? [] as $c) {
            $def    = $this->registry->get($c['field']);
            $actual = $values[$c['field']] ?? null;
            $passed = $def ? $this->compare($def['type'], $c['operator'], $actual, $c['value']) : false;

            $results[] = [
                'group_no' => (int) ($c['group_no'] ?? 1),
                'field'    => $c['field'],
                'label'    => $def['label'] ?? $c['field'],
                'operator' => $c['operator'],
                'expected' => $c['value'],
                'actual'   => $actual,
                'passed'   => $passed,
                'text'     => $this->describeCondition($c),
            ];
        }

        $satisfied = false;
        if ($results) {
            $satisfied = match ($logic) {
                'any'     => collect($results)->contains('passed', true),
                'grouped' => collect($results)->groupBy('group_no')
                                ->contains(fn ($g) => $g->every(fn ($r) => $r['passed'])),
                default   => collect($results)->every(fn ($r) => $r['passed']),
            };
        }

        return ['satisfied' => $satisfied, 'logic' => $logic, 'conditions' => $results];
    }

    public function compare(string $type, string $operator, mixed $actual, mixed $expected): bool
    {
        if ($actual === null) return false; // cannot evaluate → never qualifies

        switch ($type) {
            case RankFieldRegistry::NUMBER:
            case RankFieldRegistry::INTEGER:
                if (!is_numeric($actual)) return false;
                $a = (float) $actual;
                return match ($operator) {
                    '>='      => $a >= (float) $expected - self::EPSILON,
                    '>'       => $a >  (float) $expected + self::EPSILON,
                    '<='      => $a <= (float) $expected + self::EPSILON,
                    '<'       => $a <  (float) $expected - self::EPSILON,
                    '='       => abs($a - (float) $expected) < self::EPSILON,
                    '!='      => abs($a - (float) $expected) >= self::EPSILON,
                    'between' => is_array($expected) && count($expected) === 2
                                 && $a >= (float) $expected[0] - self::EPSILON
                                 && $a <= (float) $expected[1] + self::EPSILON,
                    default   => false,
                };

            case RankFieldRegistry::BOOLEAN:
                return $operator === '=' && (bool) $actual === filter_var($expected, FILTER_VALIDATE_BOOLEAN);

            case RankFieldRegistry::ENUM:
                $a = (string) $actual;
                return match ($operator) {
                    '='      => $a === (string) $expected,
                    '!='     => $a !== (string) $expected,
                    'in'     => is_array($expected) && in_array($a, array_map('strval', $expected), true),
                    'not_in' => is_array($expected) && !in_array($a, array_map('strval', $expected), true),
                    default  => false,
                };
        }

        return false;
    }

    /** "Total invested amount ≥ 5,000 USD" */
    public function describeCondition(array $c): string
    {
        $def    = $this->registry->get($c['field']);
        $label  = $def['label'] ?? $c['field'];
        $symbol = RankFieldRegistry::OPERATOR_SYMBOLS[$c['operator']] ?? $c['operator'];
        $unit   = $def['unit'] ?? null;

        return trim(sprintf('%s %s %s', $label, $symbol, $this->formatValue($def['type'] ?? null, $c['value'], $unit)));
    }

    public function describeRule(array $rule): string
    {
        $conds = $rule['conditions'] ?? [];
        $logic = $rule['logic'] ?? 'all';
        if (!$conds) return 'No conditions';

        if ($logic === 'grouped') {
            return collect($conds)->groupBy('group_no')->map(
                fn ($g) => '(' . $g->map(fn ($c) => $this->describeCondition($c))->implode(' AND ') . ')'
            )->implode(' OR ');
        }

        return collect($conds)->map(fn ($c) => $this->describeCondition($c))
            ->implode($logic === 'any' ? ' OR ' : ' AND ');
    }

    public function formatValue(?string $type, mixed $value, ?string $unit = null): string
    {
        $fmt = function ($v) use ($type) {
            if (is_bool($v)) return $v ? 'Yes' : 'No';
            if (is_numeric($v) && in_array($type, [RankFieldRegistry::NUMBER, RankFieldRegistry::INTEGER], true)) {
                return rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
            }
            return (string) $v;
        };

        $text = is_array($value) ? implode(' – ', array_map($fmt, $value)) : $fmt($value);
        if (is_array($value) && $type === RankFieldRegistry::ENUM) {
            $text = implode(', ', array_map($fmt, $value));
        }

        return $unit ? "$text $unit" : $text;
    }
}
