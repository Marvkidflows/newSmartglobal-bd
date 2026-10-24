<?php
// LOCATION: app/Services/Rank/AdditionalCriteriaDefaults.php
//
// The five ADDITIONAL eligibility criteria and their per-rank thresholds exactly as written in
// "SSI Investor Rank & Badge Management Module — Technical Requirements" §3 (page 2).
//
// This is DEFINITION DATA used once by the migration to seed `rank_additional_criteria` /
// `rank_additional_thresholds`. Every criterion is seeded DISABLED: the specification says SSI
// Management must confirm whether they are mandatory before production activation (§3, §11).
// After seeding, the database is the source of truth and Admins change it in the dashboard.

namespace App\Services\Rank;

class AdditionalCriteriaDefaults
{
    public const RANK_ORDER = ['bronze', 'silver', 'gold', 'platinum', 'diamond', 'elite'];

    /** @return array<string, array> */
    public static function definitions(): array
    {
        return [
            'qualified_referrals' => [
                'label' => 'Qualified referrals', 'unit' => 'referrals', 'monetary' => false, 'sort' => 1,
                'description' => 'Unique referred investors who meet the approved referral-qualification rule.',
                'thresholds' => ['bronze' => 1, 'silver' => 3, 'gold' => 5, 'platinum' => 10, 'diamond' => 20, 'elite' => 50],
            ],
            'active_investment_usd' => [
                'label' => 'Current active investment', 'unit' => 'USD', 'monetary' => true, 'sort' => 2,
                'description' => 'Verified-USD principal of investments that are currently active.',
                'thresholds' => ['bronze' => 100, 'silver' => 500, 'gold' => 2500, 'platinum' => 5000, 'diamond' => 10000, 'elite' => 25000],
            ],
            'approved_deposits_usd' => [
                'label' => 'Total approved deposits', 'unit' => 'USD', 'monetary' => true, 'sort' => 3,
                'description' => 'Verified-USD total of deposits that were approved and credited.',
                'thresholds' => ['bronze' => 100, 'silver' => 1000, 'gold' => 5000, 'platinum' => 10000, 'diamond' => 20000, 'elite' => 50000],
            ],
            'qualifying_investments' => [
                'label' => 'Qualifying investments', 'unit' => 'investments', 'monetary' => false, 'sort' => 4,
                'description' => 'Number of verified, non-cancelled, non-duplicate investment records (each counted once).',
                'thresholds' => ['bronze' => 1, 'silver' => 2, 'gold' => 3, 'platinum' => 5, 'diamond' => 8, 'elite' => 12],
            ],
            'longest_duration_days' => [
                'label' => 'Longest qualifying duration', 'unit' => 'days', 'monetary' => false, 'sort' => 5,
                'description' => 'Longest investment duration, measured from the recorded start date and end date.',
                'thresholds' => ['bronze' => 7, 'silver' => 14, 'gold' => 30, 'platinum' => 60, 'diamond' => 90, 'elite' => 180],
            ],
        ];
    }

    /**
     * Criteria whose meaning the specification leaves to SSI Management. They cannot be ENABLED until an
     * authorised Admin records which interpretation Management chose (nothing is guessed).
     *
     * @return array<string, array{policy_key:string, question:string, options:array<string,string>}>
     */
    public static function policies(): array
    {
        return [
            'qualified_referrals' => [
                'policy_key' => 'qualification',
                'question'   => 'Which referred investors count as "qualified"? (Spec §7: "the approved qualification rules" — not defined in the document.)',
                'options'    => [
                    'active_account'         => 'Unique referred investor with an active account',
                    'verified_account'       => 'Unique referred investor with an active account and approved KYC',
                    'verified_and_invested'  => 'Active, KYC-approved referred investor who has at least one non-cancelled investment',
                ],
            ],
            'longest_duration_days' => [
                'policy_key' => 'basis',
                'question'   => 'Does the duration requirement refer to completed investments or to time elapsed in an active investment? (Spec §7: Management must specify.)',
                'options'    => [
                    'completed'      => 'Completed investments only (recorded start date to recorded end date, cycle genuinely ended)',
                    'elapsed_active' => 'Time elapsed so far in a currently active investment (start date to today)',
                ],
            ],
        ];
    }
}
