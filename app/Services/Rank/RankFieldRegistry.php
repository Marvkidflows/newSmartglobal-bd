<?php
// LOCATION: app/Services/Rank/RankFieldRegistry.php
//
// The ONLY place that defines which investor attributes a rank criterion may
// test. Admin picks from this list in the UI; the validator rejects anything
// else, so no free-form SQL/code can ever reach the evaluator.
//
// EXTENDING (developers): call register() with a new key, e.g. from a service
// provider:
//   app(RankFieldRegistry::class)->register('referral_count', [...]);
// A definition needs: label, type (number|integer|boolean|enum), a batch
// `resolver` (callable receiving int[] user ids, returning [userId => value]
// for users that have a value) and optional unit / options / default /
// description. Resolvers MUST be read-only.

namespace App\Services\Rank;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class RankFieldRegistry
{
    public const NUMBER  = 'number';
    public const INTEGER = 'integer';
    public const BOOLEAN = 'boolean';
    public const ENUM    = 'enum';

    public const OPERATOR_LABELS = [
        '>='      => 'is at least',
        '>'       => 'is more than',
        '<='      => 'is at most',
        '<'       => 'is less than',
        '='       => 'equals',
        '!='      => 'does not equal',
        'between' => 'is between (inclusive)',
        'in'      => 'is one of',
        'not_in'  => 'is not one of',
    ];

    public const OPERATOR_SYMBOLS = [
        '>=' => '≥', '>' => '>', '<=' => '≤', '<' => '<', '=' => '=', '!=' => '≠',
        'between' => 'between', 'in' => 'in', 'not_in' => 'not in',
    ];

    protected array $fields = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    public function register(string $key, array $definition): void
    {
        foreach (['label', 'type', 'resolver'] as $required) {
            if (!array_key_exists($required, $definition)) {
                throw new \InvalidArgumentException("Rank field [$key] is missing [$required].");
            }
        }
        if (!in_array($definition['type'], [self::NUMBER, self::INTEGER, self::BOOLEAN, self::ENUM], true)) {
            throw new \InvalidArgumentException("Rank field [$key] has unsupported type.");
        }
        $this->fields[$key] = $definition + ['unit' => null, 'options' => [], 'default' => null, 'description' => null, 'monetary' => false];
    }

    /** Does this field measure a MONEY amount (and so depend on the currency being confirmed)? */
    public function isMonetary(string $key): bool
    {
        return (bool) ($this->fields[$key]['monetary'] ?? false);
    }

    public function has(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    public function get(string $key): ?array
    {
        return $this->fields[$key] ?? null;
    }

    public function keys(): array
    {
        return array_keys($this->fields);
    }

    public static function operatorsForType(string $type): array
    {
        return match ($type) {
            self::NUMBER, self::INTEGER => ['>=', '>', '<=', '<', '=', '!=', 'between'],
            self::BOOLEAN               => ['='],
            self::ENUM                  => ['=', '!=', 'in', 'not_in'],
            default                     => [],
        };
    }

    /** Schema consumed by the Admin criteria builder (no resolver closures). */
    public function schema(): array
    {
        $fields = [];
        foreach ($this->fields as $key => $def) {
            $fields[] = [
                'key'         => $key,
                'label'       => $def['label'],
                'type'        => $def['type'],
                'unit'        => $def['unit'],
                'options'     => $def['options'],
                'description' => $def['description'],
                'monetary'    => (bool) $def['monetary'],
                'operators'   => self::operatorsForType($def['type']),
            ];
        }

        return [
            'fields'    => $fields,
            'operators' => collect(self::OPERATOR_LABELS)->map(fn ($label, $op) => [
                'key' => $op, 'label' => $label, 'symbol' => self::OPERATOR_SYMBOLS[$op],
            ])->values()->all(),
            'logic'     => [
                ['key' => 'all',     'label' => 'ALL conditions must be met'],
                ['key' => 'any',     'label' => 'ANY ONE condition is enough'],
                ['key' => 'grouped', 'label' => 'Custom: conditions in a group must ALL be met; meeting ANY ONE group is enough'],
            ],
        ];
    }

    /**
     * Resolve the requested fields for many investors using one grouped query
     * per field (no per-investor queries).
     *
     * @return array<int, array<string, mixed>> [userId => [fieldKey => value]]
     */
    public function resolveMany(array $userIds, array $fieldKeys): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $out = array_fill_keys($userIds, []);
        if (!$userIds) return $out;

        foreach (array_unique($fieldKeys) as $key) {
            $def = $this->fields[$key] ?? null;
            if (!$def) continue;
            $resolved = ($def['resolver'])($userIds);
            foreach ($userIds as $id) {
                $out[$id][$key] = array_key_exists($id, $resolved) ? $resolved[$id] : $def['default'];
            }
        }

        return $out;
    }

    // ── Built-in fields (all derived from data that already exists) ───────────
    protected function registerDefaults(): void
    {
        // Rank thresholds are in USD. A stored number is NEVER assumed to be USD: money fields use only
        // amounts that a person has verified in USD (see RankInvestmentLedger / UsdValuationService).
        $cur = config('ranks.ranking_currency', 'USD');
        $ledger = fn () => app(RankInvestmentLedger::class);

        $this->register('total_invested', [
            'label'       => 'Total invested amount (verified ' . $cur . ')',
            'type'        => self::NUMBER,
            'unit'        => $cur,
            'monetary'    => true,
            'default'     => 0.0,
            'description' => 'Cumulative total of the investor\'s non-cancelled investments (active + completed), in ' . $cur
                . '. Only investments with a verified ' . $cur . ' value count; unverified or stale ones add nothing and are flagged for review. Duplicate records are excluded.',
            'resolver'    => fn (array $ids) => collect($ledger()->forUsers($ids))->map(fn ($f) => $f['total_usd'])->all(),
        ]);

        $this->register('active_investment_amount', [
            'label'       => 'Currently active investment amount (verified ' . $cur . ')',
            'type'        => self::NUMBER,
            'unit'        => $cur,
            'monetary'    => true,
            'default'     => 0.0,
            'description' => 'Verified-' . $cur . ' total of investments with status "active". Unverified, stale, cancelled and duplicate records are not counted.',
            'resolver'    => fn (array $ids) => collect($ledger()->forUsers($ids))->map(fn ($f) => $f['active_usd'])->all(),
        ]);

        $this->register('total_approved_deposits', [
            'label'       => 'Total approved deposits (verified ' . $cur . ')',
            'type'        => self::NUMBER,
            'unit'        => $cur,
            'monetary'    => true,
            'default'     => 0.0,
            'description' => 'Verified-' . $cur . ' total of approved deposits. Deposits without a verified ' . $cur . ' value add nothing and are flagged for review.',
            'resolver'    => fn (array $ids) => collect($ledger()->depositsForUsers($ids))->map(fn ($f) => $f['total_usd'])->all(),
        ]);

        $this->register('investment_count', [
            'label'       => 'Number of investments',
            'type'        => self::INTEGER,
            'default'     => 0,
            'description' => 'Count of non-cancelled investment records, each counted once. Duplicate records are excluded.',
            'resolver'    => fn (array $ids) => collect($ledger()->forUsers($ids))->map(fn ($f) => $f['count'])->all(),
        ]);

        // "Completed investment cycle": one investment RECORD, counted once, when its status is completed, its
        // amount is positive, and it has genuinely ended — both the recorded end date and the plan-scheduled end
        // have been reached. An Admin completing early (or shortening the countdown) does not make it count;
        // editing/extending an investment changes the same record, so it never adds a cycle. Cancelled and
        // duplicate records are excluded. Funding source (wallet vs deposit) is irrelevant: no reinvestment
        // marker exists, so none is assumed.
        $this->register('completed_investment_count', [
            'label'       => 'Completed investment cycles',
            'type'        => self::INTEGER,
            'default'     => 0,
            'description' => 'Investment records with status completed, a positive amount, and whose scheduled end date has been reached. Early completions, active, cancelled and duplicate records are not counted; each record counts once.',
            'resolver'    => fn (array $ids) => collect($ledger()->forUsers($ids))->map(fn ($f) => $f['cycles'])->all(),
        ]);

        $this->register('longest_investment_duration_days', [
            'label'       => 'Longest investment duration',
            'type'        => self::INTEGER,
            'unit'        => 'days',
            'default'     => 0,
            'description' => 'Longest plan duration among the investor\'s non-cancelled investments.',
            'resolver'    => fn (array $ids) => DB::table('investment_accounts')
                ->join('investment_plans', 'investment_plans.id', '=', 'investment_accounts.investment_plan_id')
                ->whereIn('investment_accounts.user_id', $ids)
                ->where('investment_accounts.status', '!=', 'cancelled')
                ->groupBy('investment_accounts.user_id')
                ->selectRaw('investment_accounts.user_id as user_id, MAX(investment_plans.duration_days) as v')
                ->pluck('v', 'user_id')->map(fn ($v) => (int) $v)->all(),
        ]);

        $this->register('account_age_days', [
            'label'       => 'Account age',
            'type'        => self::INTEGER,
            'unit'        => 'days',
            'default'     => 0,
            'description' => 'Days since the account was created.',
            'resolver'    => function (array $ids) {
                $out = [];
                foreach (User::whereIn('id', $ids)->get(['id', 'created_at']) as $u) {
                    $out[$u->id] = $u->created_at ? (int) floor($u->created_at->diffInDays(now(), true)) : 0;
                }
                return $out;
            },
        ]);

        $this->register('kyc_status', [
            'label'       => 'Account verification (KYC) status',
            'type'        => self::ENUM,
            'options'     => ['approved', 'pending', 'under_review', 'rejected', 'not_submitted'],
            'default'     => 'not_submitted',
            'resolver'    => function (array $ids) {
                $out = [];
                foreach (User::whereIn('id', $ids)->get(['id', 'kyc_status', 'id_document_path']) as $u) {
                    $out[$u->id] = $u->kyc_status ?: ($u->id_document_path ? 'pending' : 'not_submitted');
                }
                return $out;
            },
        ]);

        $this->register('kyc_verified', [
            'label'       => 'Account is verified',
            'type'        => self::BOOLEAN,
            'default'     => false,
            'resolver'    => fn (array $ids) => User::whereIn('id', $ids)->pluck('kyc_verified', 'id')
                ->map(fn ($v) => (bool) $v)->all(),
        ]);

        $this->register('referral_count', [
            'label'       => 'Number of referrals',
            'type'        => self::INTEGER,
            'default'     => 0,
            'resolver'    => fn (array $ids) => User::whereIn('id', $ids)->pluck('referral_count', 'id')
                ->map(fn ($v) => (int) $v)->all(),
        ]);
    }
}
