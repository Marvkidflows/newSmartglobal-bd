<?php
// LOCATION: app/Services/Rank/RankCriteriaValidator.php
//
// Validates a proposed criterion BEFORE it is saved/activated. Produces:
//   errors    — malformed input (missing value, bad operator/type, unknown field…)
//   conflicts — rules that cannot work or contradict other active ranks
//   warnings  — non-blocking things Admin should know (e.g. a rule will be replaced)
// Conflicts and errors both block saving as ACTIVE; errors always block saving.

namespace App\Services\Rank;

use App\Models\InvestorRank;
use App\Models\RankCriterion;

class RankCriteriaValidator
{
    public const MAX_CONDITIONS = 20;
    public const MAX_GROUPS     = 10;
    private const MAX_NUMBER    = 1_000_000_000_000;

    public function __construct(
        protected RankFieldRegistry $registry,
        protected RankRuleEvaluator $evaluator,
    ) {}

    /**
     * @param array $payload   Raw payload (investor_rank_id, name, logic, conditions[], is_active)
     * @param ?int  $ignoreCriterionId  Criterion being edited (excluded from cross-rank checks)
     * @return array{valid: bool, errors: array, conflicts: array, warnings: array, normalized: array}
     */
    public function validate(array $payload, ?int $ignoreCriterionId = null, ?int $rankId = null, bool $enforceCurrencyGate = true): array
    {
        $errors = []; $conflicts = []; $warnings = [];
        $n = $this->normalize($payload);
        $rankId = $rankId ?? ($n['investor_rank_id'] ?? null);

        $rank = $rankId ? InvestorRank::find($rankId) : null;
        if (!$rank) $errors['investor_rank_id'][] = 'Select a valid rank.';

        if ($n['name'] === '' ) $errors['name'][] = 'A criteria name is required.';
        elseif (mb_strlen($n['name']) > 120) $errors['name'][] = 'The name may not exceed 120 characters.';

        if (!in_array($n['logic'], ['all', 'any', 'grouped'], true)) {
            $errors['logic'][] = 'Combination logic must be "all", "any" or "grouped".';
        }

        $conds = $n['conditions'];
        if (count($conds) < 1) {
            $errors['conditions'][] = 'Add at least one condition. A rank with no conditions cannot be evaluated.';
        } elseif (count($conds) > self::MAX_CONDITIONS) {
            $errors['conditions'][] = 'A criterion may contain at most ' . self::MAX_CONDITIONS . ' conditions.';
        }

        $seen = [];
        foreach ($conds as $i => $c) {
            $p   = "conditions.$i";
            $def = $this->registry->get((string) $c['field']);

            if (!$def) {
                $errors["$p.field"][] = $c['field'] === ''
                    ? 'Choose an investor field.'
                    : "'{$c['field']}' is not a supported investor field.";
                continue;
            }

            $allowed = RankFieldRegistry::operatorsForType($def['type']);
            if (!in_array($c['operator'], $allowed, true)) {
                $errors["$p.operator"][] = "Operator '{$c['operator']}' cannot be used with '{$def['label']}'. Allowed: "
                    . implode(', ', $allowed) . '.';
                continue;
            }

            if ($err = $this->checkValue($def, $c['operator'], $c['value'])) {
                $errors["$p.value"][] = $err;
                continue;
            }

            if ($n['logic'] === 'grouped') {
                if ($c['group_no'] < 1 || $c['group_no'] > self::MAX_GROUPS) {
                    $errors["$p.group_no"][] = 'Group number must be between 1 and ' . self::MAX_GROUPS . '.';
                }
            }

            $sig = $c['group_no'] . '|' . $c['field'] . '|' . $c['operator'] . '|' . json_encode($c['value']);
            if (isset($seen[$sig])) {
                $errors["$p"][] = "Duplicate condition: {$this->evaluator->describeCondition($c)}.";
            }
            $seen[$sig] = true;
        }

        // Only run structural/contradiction analysis once every row is well-formed.
        if (!$errors) {
            foreach ($this->andSets($n) as $label => $set) {
                foreach ($this->contradictions($set) as $msg) {
                    $conflicts[] = ['type' => 'unsatisfiable', 'message' => ($label ? "$label: " : '') . $msg];
                }
            }

            if ($n['logic'] === 'grouped') {
                $groups = collect($conds)->pluck('group_no')->unique()->sort()->values();
                if ($groups->count() < 2) {
                    $warnings[] = 'Only one group is defined, so this behaves exactly like "ALL conditions".';
                }
            }

            if ($enforceCurrencyGate && !empty($n['is_active'])) {
                $money = collect($conds)->pluck('field')->unique()->filter(fn ($f) => $this->registry->isMonetary($f))
                    ->map(fn ($f) => $this->registry->get($f)['label'])->values();
                if ($money->isNotEmpty()) {
                    // Why it is blocked (and where to fix it) comes from the confirmed currency policy — not from an env flag.
                    foreach (app(CurrencyPolicyService::class)->blockers($money->all()) as $blocker) {
                        $conflicts[] = $blocker;
                    }
                }
            }

            // Readiness: money criteria only see records with a verified USD value.
            if (collect($conds)->contains(fn ($c) => $this->registry->isMonetary($c['field']))) {
                $s = app(\App\Services\Rank\UsdValuationService::class)->summary();
                $left = $s['investments']['unverified'] + $s['investments']['stale'] + $s['investments']['duplicate_suspects']
                      + $s['deposits']['unverified'] + $s['deposits']['stale'];
                if ($left > 0) {
                    $warnings[] = "{$left} investment/deposit record(s) have no verified USD value (unknown currency, changed since verification, or look like duplicates). "
                        . 'They add nothing to the amount totals until Financial reviews them (Investor Ranks → Currency).';
                }
            }

            if ($rank && !empty($n['is_active'])) {
                [$c2, $w2] = $this->crossRank($n, $rank, $ignoreCriterionId);
                $conflicts = array_merge($conflicts, $c2);
                $warnings  = array_merge($warnings, $w2);
            }
        }

        return [
            'valid'      => !$errors && !$conflicts,
            'errors'     => $errors,
            'conflicts'  => $conflicts,
            'warnings'   => $warnings,
            'normalized' => $n,
        ];
    }

    // ── Normalisation ─────────────────────────────────────────────────────────
    public function normalize(array $p): array
    {
        $conds = [];
        foreach (array_values($p['conditions'] ?? []) as $i => $c) {
            $c = is_array($c) ? $c : [];
            $field = trim((string) ($c['field'] ?? ''));
            $op    = trim((string) ($c['operator'] ?? ''));
            $def   = $this->registry->get($field);
            $conds[] = [
                'group_no' => (int) ($c['group_no'] ?? 1) ?: 1,
                'field'    => $field,
                'operator' => $op,
                'value'    => $this->castValue($def['type'] ?? null, $op, $c['value'] ?? null),
                'position' => $i,
            ];
        }

        $logic = (string) ($p['logic'] ?? 'all');
        if ($logic !== 'grouped') {
            foreach ($conds as &$c) $c['group_no'] = 1;
            unset($c);
        }

        return [
            'investor_rank_id' => isset($p['investor_rank_id']) ? (int) $p['investor_rank_id'] : null,
            'name'             => trim((string) ($p['name'] ?? '')),
            'description'      => isset($p['description']) ? trim((string) $p['description']) : null,
            'logic'            => $logic,
            'is_active'        => (bool) ($p['is_active'] ?? false),
            'conditions'       => $conds,
        ];
    }

    protected function castValue(?string $type, string $op, mixed $v): mixed
    {
        if ($type === RankFieldRegistry::NUMBER || $type === RankFieldRegistry::INTEGER) {
            if ($op === 'between') {
                $arr = is_array($v) ? array_values($v) : [];
                return array_map(fn ($x) => is_numeric($x) ? $x + 0 : $x, $arr);
            }
            return is_numeric($v) ? $v + 0 : $v;
        }
        if ($type === RankFieldRegistry::BOOLEAN) {
            $b = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return $b === null ? $v : $b;
        }
        if ($type === RankFieldRegistry::ENUM) {
            return in_array($op, ['in', 'not_in'], true)
                ? array_values(array_map('strval', is_array($v) ? $v : (($v === null || $v === '') ? [] : [$v])))
                : (is_scalar($v) ? (string) $v : $v);
        }
        return $v;
    }

    protected function checkValue(array $def, string $op, mixed $v): ?string
    {
        switch ($def['type']) {
            case RankFieldRegistry::NUMBER:
            case RankFieldRegistry::INTEGER:
                $vals = $op === 'between' ? $v : [$v];
                if ($op === 'between' && (!is_array($v) || count($v) !== 2)) {
                    return 'Provide both a minimum and a maximum value.';
                }
                foreach ($vals as $x) {
                    if ($x === null || $x === '' || !is_numeric($x)) return 'Enter a valid number.';
                    if ($x < 0) return 'Value cannot be negative.';
                    if ($x > self::MAX_NUMBER) return 'Value is too large.';
                    if ($def['type'] === RankFieldRegistry::INTEGER && floor($x) != $x) return 'Enter a whole number.';
                }
                if ($op === 'between' && $v[0] > $v[1]) return 'The minimum cannot be greater than the maximum.';
                return null;

            case RankFieldRegistry::BOOLEAN:
                return is_bool($v) ? null : 'Choose Yes or No.';

            case RankFieldRegistry::ENUM:
                $vals = in_array($op, ['in', 'not_in'], true) ? $v : [$v];
                if (!is_array($vals) || !$vals) return 'Choose at least one value.';
                foreach ($vals as $x) {
                    if (!in_array($x, $def['options'], true)) {
                        return "'$x' is not a valid option. Allowed: " . implode(', ', $def['options']) . '.';
                    }
                }
                return null;
        }
        return 'Unsupported field type.';
    }

    // ── Structural analysis ───────────────────────────────────────────────────
    /** The sets of conditions that must hold TOGETHER (an AND-set). */
    protected function andSets(array $n): array
    {
        $conds = $n['conditions'];
        return match ($n['logic']) {
            'any'     => [],                                  // single conditions can't contradict each other
            'grouped' => collect($conds)->groupBy('group_no')
                            ->mapWithKeys(fn ($g, $k) => ["Group $k" => $g->all()])->all(),
            default   => ['' => $conds],
        };
    }

    /** Detects an AND-set that no investor could ever satisfy. */
    protected function contradictions(array $set): array
    {
        $msgs = [];
        foreach (collect($set)->groupBy('field') as $field => $conds) {
            $def   = $this->registry->get($field);
            $label = $def['label'];

            if (in_array($def['type'], [RankFieldRegistry::NUMBER, RankFieldRegistry::INTEGER], true)) {
                $lo = -INF; $loInc = true; $hi = INF; $hiInc = true; $neq = [];
                foreach ($conds as $c) {
                    $v = $c['value'];
                    switch ($c['operator']) {
                        case '>=': [$lo, $loInc] = $this->tighterLo($lo, $loInc, (float) $v, true); break;
                        case '>':  [$lo, $loInc] = $this->tighterLo($lo, $loInc, (float) $v, false); break;
                        case '<=': [$hi, $hiInc] = $this->tighterHi($hi, $hiInc, (float) $v, true); break;
                        case '<':  [$hi, $hiInc] = $this->tighterHi($hi, $hiInc, (float) $v, false); break;
                        case '=':
                            [$lo, $loInc] = $this->tighterLo($lo, $loInc, (float) $v, true);
                            [$hi, $hiInc] = $this->tighterHi($hi, $hiInc, (float) $v, true);
                            break;
                        case 'between':
                            [$lo, $loInc] = $this->tighterLo($lo, $loInc, (float) $v[0], true);
                            [$hi, $hiInc] = $this->tighterHi($hi, $hiInc, (float) $v[1], true);
                            break;
                        case '!=': $neq[] = (float) $v; break;
                    }
                }
                $empty = $lo > $hi || ($lo == $hi && !($loInc && $hiInc));
                if ($empty) {
                    $msgs[] = "The conditions on '$label' contradict each other, so no investor could ever qualify.";
                } elseif ($lo == $hi && in_array($lo, $neq, true)) {
                    $msgs[] = "'$label' is required to equal and not equal the same value.";
                }
            } else {
                $allowed = null; $excluded = [];
                foreach ($conds as $c) {
                    $vals = is_array($c['value']) ? $c['value'] : [$c['value']];
                    $vals = array_map(fn ($x) => is_bool($x) ? ($x ? '1' : '0') : (string) $x, $vals);
                    if (in_array($c['operator'], ['=', 'in'], true)) {
                        $allowed = $allowed === null ? $vals : array_values(array_intersect($allowed, $vals));
                    } else {
                        $excluded = array_merge($excluded, $vals);
                    }
                }
                if ($allowed !== null && !array_diff($allowed, $excluded)) {
                    $msgs[] = "The conditions on '$label' contradict each other, so no investor could ever qualify.";
                }
            }
        }
        return $msgs;
    }

    private function tighterLo(float $lo, bool $inc, float $v, bool $vInc): array
    {
        if ($v > $lo) return [$v, $vInc];
        if ($v == $lo) return [$lo, $inc && $vInc];
        return [$lo, $inc];
    }

    private function tighterHi(float $hi, bool $inc, float $v, bool $vInc): array
    {
        if ($v < $hi) return [$v, $vInc];
        if ($v == $hi) return [$hi, $inc && $vInc];
        return [$hi, $inc];
    }

    // ── Cross-rank consistency (active criteria only) ─────────────────────────
    protected function crossRank(array $n, InvestorRank $rank, ?int $ignoreId): array
    {
        $conflicts = []; $warnings = [];
        $mySig = $this->signature($n);

        $others = RankCriterion::query()->where('is_active', true)->with(['rank', 'conditions'])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->get();

        foreach ($others as $o) {
            if ($o->investor_rank_id === $rank->id) {
                $warnings[] = "The currently active criteria for {$rank->name} (\"{$o->name}\") will be deactivated and replaced when this is activated.";
                continue;
            }

            $oRule = $o->toRule();
            if ($this->signature($oRule) === $mySig) {
                $conflicts[] = [
                    'type'    => 'ambiguous',
                    'message' => "These conditions are identical to the active criteria for {$o->rank->name}. An investor meeting them could not be classified unambiguously.",
                ];
                continue;
            }

            $higher = $o->rank->level > $rank->level ? ['rule' => $oRule, 'rank' => $o->rank] : ['rule' => $n, 'rank' => $rank];
            $lower  = $o->rank->level > $rank->level ? ['rule' => $n, 'rank' => $rank]         : ['rule' => $oRule, 'rank' => $o->rank];

            if (!$this->isSimpleAnd($higher['rule']) || !$this->isSimpleAnd($lower['rule'])) {
                $warnings[] = "Rank ordering against {$o->rank->name} could not be verified automatically because one of the rules uses ANY/grouped logic.";
                continue;
            }

            $hb = $this->lowerBounds($higher['rule']); $lb = $this->lowerBounds($lower['rule']);
            foreach (array_intersect_key($hb, $lb) as $field => $hv) {
                if ($hv < $lb[$field]) {
                    $label = $this->registry->get($field)['label'];
                    $conflicts[] = [
                        'type'    => 'rank_order',
                        'message' => "Invalid rank ordering on '$label': {$higher['rank']->name} requires at least "
                            . $this->num($hv) . ", which is LOWER than the " . $this->num($lb[$field])
                            . " required for {$lower['rank']->name}. Higher ranks must not be easier to reach.",
                    ];
                }
            }
        }

        return [$conflicts, $warnings];
    }

    protected function isSimpleAnd(array $rule): bool
    {
        $logic = $rule['logic'] ?? 'all';
        if ($logic === 'all') return true;
        if ($logic === 'grouped') return collect($rule['conditions'])->pluck('group_no')->unique()->count() === 1;
        return count($rule['conditions']) === 1;
    }

    /** field => effective numeric lower bound, for fields with a lower bound. */
    protected function lowerBounds(array $rule): array
    {
        $out = [];
        foreach ($rule['conditions'] as $c) {
            $def = $this->registry->get($c['field']);
            if (!$def || !in_array($def['type'], [RankFieldRegistry::NUMBER, RankFieldRegistry::INTEGER], true)) continue;
            $v = match ($c['operator']) {
                '>=', '>', '=' => (float) $c['value'],
                'between'      => (float) $c['value'][0],
                default        => null,
            };
            if ($v !== null) $out[$c['field']] = max($out[$c['field']] ?? -INF, $v);
        }
        return $out;
    }

    protected function signature(array $rule): string
    {
        $parts = collect($rule['conditions'])->map(fn ($c) => ($rule['logic'] === 'grouped' ? $c['group_no'] : 1)
            . '|' . $c['field'] . '|' . $c['operator'] . '|' . json_encode($c['value']))->sort()->values()->all();
        return ($rule['logic'] ?? 'all') . '#' . implode(';', $parts);
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2), '0'), '.');
    }
}
