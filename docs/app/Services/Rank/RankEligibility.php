<?php
// LOCATION: app/Services/Rank/RankEligibility.php
//
// Compliance PREREQUISITES for holding a rank, independent of the Admin-configured
// criteria: the account must be active and (if required) verified. They are evaluated
// from the investor's own database record under the same row lock as the rank decision.
//
//  • Automatic evaluation grants nothing while a prerequisite fails.
//  • A manual grant while a prerequisite fails is an exception → override policy applies.
//  • If a ranked investor later stops meeting one, they are FLAGGED for Admin review —
//    never downgraded automatically.

namespace App\Services\Rank;

use App\Models\User;

class RankEligibility
{
    /** What is currently required (for the Admin UI). */
    public function requirements(): array
    {
        return array_values(array_filter([
            config('ranks.require_active_account') ? [
                'key' => 'account_active', 'label' => 'Account is active',
                'description' => 'Suspended, frozen and deactivated accounts cannot be granted a rank.',
            ] : null,
            config('ranks.require_verified_kyc') ? [
                'key' => 'kyc_approved', 'label' => 'Account is verified (KYC approved)',
                'description' => 'Identity verification must be approved by Admin.',
            ] : null,
        ]));
    }

    /**
     * @return array{eligible: bool, requirements: array, failures: array}
     *   failures use the same shape as a failed criteria condition so reviews/audit can show them.
     */
    public function check(User $user): array
    {
        $rows = []; $failures = [];

        if (config('ranks.require_active_account')) {
            $status = $user->status ?: 'active';
            $met = $status === 'active';
            $rows[] = ['key' => 'account_active', 'label' => 'Account is active', 'met' => $met, 'actual' => $status];
            if (!$met) $failures[] = $this->entry('account_active', 'Account is active', $status);
        }

        if (config('ranks.require_verified_kyc')) {
            $kyc = $user->kyc_status_safe;
            $met = $kyc === 'approved' && (bool) $user->kyc_verified;
            $rows[] = ['key' => 'kyc_approved', 'label' => 'Account is verified (KYC approved)', 'met' => $met, 'actual' => $kyc];
            if (!$met) $failures[] = $this->entry('kyc_approved', 'Account is verified (KYC approved)', $kyc);
        }

        return ['eligible' => !$failures, 'requirements' => $rows, 'failures' => $failures];
    }

    protected function entry(string $key, string $label, mixed $actual): array
    {
        return [
            'group_no' => 0, 'field' => "prerequisite:$key", 'label' => $label, 'operator' => '=',
            'expected' => true, 'actual' => $actual, 'passed' => false,
            'text' => "$label (currently: " . str_replace('_', ' ', (string) $actual) . ')',
        ];
    }
}
