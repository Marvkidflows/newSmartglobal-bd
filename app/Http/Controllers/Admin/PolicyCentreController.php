<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReferralPolicy;
use App\Models\User;
use App\Models\WithdrawalFeePolicy;

/**
 * Policy & Configuration Centre — READ-ONLY catalogue (admin route group).
 *
 * Nothing here changes any setting. Each entry reports its current value, documented default, source, approval and activation state,
 * who last changed it (where the data model records that) and whether independent approval is required. Where Management has not
 * decided, the entry says so instead of showing an invented value. Environment-controlled switches are reported as environment
 * decisions, because they are deliberately not togglable from the website (see config/lending.php).
 */
class PolicyCentreController extends Controller
{
    private function flag(bool $v): string { return $v ? 'ON' : 'OFF'; }

    public function index()
    {
        $fee = WithdrawalFeePolicy::current();
        $ref = ReferralPolicy::current();
        $fc  = config('financial_controls');
        $rk  = config('ranks');
        $fin = config('financial');

        $e = fn (string $cat, string $key, string $name, $current, $default, string $source, string $approval, string $activation, ?string $changedBy, string $control, bool $independent, string $where, string $note = '') =>
            compact('cat', 'key', 'name', 'current', 'default', 'source', 'approval', 'activation', 'control', 'independent', 'where', 'note') + ['last_changed_by' => $changedBy];

        $items = [
            $e('Withdrawal fees', 'withdrawal_fee', 'Withdrawal fee policy',
                $fee ? "{$fee->fee_type}" . ($fee->fixed_amount ? " {$fee->fixed_amount} USD" : '') . ($fee->percentage ? " {$fee->percentage}%" : '') : 'No policy recorded',
                'No fee (none)', 'SSI_MANAGEMENT_DECISIONS_REQUIRED.md', $fee && $fee->policy_approved ? 'Management-approved' : 'Awaiting Management decision',
                $fee && $fee->is_active && $fee->policy_approved && $fee->fee_type !== 'none' ? 'ACTIVE' : 'INACTIVE', $fee->configured_by_name ?? null,
                'Versioned in database; draft → approval by a different Administrator', (bool) ($fc['policy_dual_control'] ?? true), '/financial/withdrawal-fees'),
            $e('Referrals', 'referral_policy', 'Referral reward policy',
                $ref ? "{$ref->reward_amount} USD per eligible referral" : 'No policy recorded', 'No reward', 'SSI_MANAGEMENT_DECISIONS_REQUIRED.md',
                $ref && $ref->policy_approved ? 'Management-approved' : 'Awaiting Management decision', $ref && $ref->policy_approved ? 'ACTIVE' : 'INACTIVE',
                $ref->changed_by_name ?? null, 'Versioned in database; draft → approval by a different Administrator', (bool) ($fc['policy_dual_control'] ?? true), '/financial/referrals'),
            $e('Referrals', 'referral_separate_credit', 'Referral reward credit requires a separate person', $this->flag((bool) ($fc['referral_separate_credit'] ?? true)), 'ON', 'config/financial_controls.php', 'Safety control', 'Environment-controlled', null, 'Environment (FIN_REFERRAL_SEPARATE_CREDIT)', true, '/financial/referrals'),
            $e('Approvals', 'dual_approval', 'Dual approval for sensitive financial actions', $this->flag((bool) ($fc['dual_approval_enabled'] ?? true)), 'ON', 'config/financial_controls.php', 'Safety control', 'Environment-controlled', null, 'Environment (FIN_DUAL_APPROVAL_ENABLED)', true, '/admin/approvals'),
            $e('Approvals', 'approval_threshold', 'Balance-change approval threshold (USD)', (string) ($fc['balance_threshold_usd'] ?? ''), '10000.00', 'config/financial_controls.php', 'Provisional — awaiting Management decision', 'Environment-controlled', null, 'Environment (FIN_APPROVAL_THRESHOLD_USD)', false, '/admin/approvals', 'Threshold value is a provisional default, not a Management-approved limit.'),
            $e('Approvals', 'policy_dual_control', 'Policy changes need a second Administrator (D-04)', $this->flag((bool) ($fc['policy_dual_control'] ?? true)), 'ON', 'config/financial_controls.php', 'Safety control', 'Environment-controlled', null, 'Environment (FIN_POLICY_DUAL_CONTROL)', true, '/financial/withdrawal-fees'),
            $e('Ranks & benefits', 'rank_require_approval', 'Rank changes require approval', $this->flag((bool) ($rk['require_approval'] ?? true)), 'ON', 'config/ranks.php', 'Safety control', 'Environment-controlled', null, 'Environment (RANKS_REQUIRE_APPROVAL)', true, '/admin/ranks/approvals'),
            $e('Ranks & benefits', 'rank_manual_override', 'Manual rank override', $this->flag((bool) ($rk['allow_manual_override'] ?? false)), 'OFF', 'config/ranks.php', 'Not approved', 'Restricted — kept OFF', null, 'Environment (RANKS_ALLOW_MANUAL_OVERRIDE)', true, '/admin/ranks/controls'),
            $e('Ranks & benefits', 'rank_criteria', 'Rank criteria (Bronze → Elite thresholds)', 'Proposed thresholds held as inactive drafts', 'Inactive', 'config/ranks_proposed.php', 'Awaiting Management decision', 'INACTIVE until approved', null, 'Admin → Investor Ranks → Rank Criteria', true, '/admin/ranks/criteria'),
            $e('Profit & ROI incentives', 'incentives', 'Rank incentives / ROI incentives', $this->flag((bool) ($rk['incentives_activation_enabled'] ?? false)), 'OFF', 'config/ranks.php', 'Awaiting Management decision', 'Restricted — kept OFF', null, 'Environment (RANKS_INCENTIVES_ACTIVATION_ENABLED)', true, '/admin/ranks/benefits'),
            $e('Lending', 'lending', 'Lending programme (Diamond / Elite)', $this->flag((bool) config('lending.enabled')), 'OFF', 'config/lending.php (Board decision §6)', 'Not authorised by the Board', 'HARD-LOCKED', null, 'Environment only — never an admin toggle', true, '/admin/ranks/benefits'),
            $e('Currency & exchange', 'currency_policy', 'Currency policy (USD) confirmation', 'Review recorded by Financial; confirmation by Admin', 'Unconfirmed', 'config/ranks.php', 'Admin confirmation required', 'Gate for money-based criteria', null, 'Financial review + Admin confirm', true, '/financial/currency'),
            $e('Currency & exchange', 'live_fx', 'Live FX rates', $this->flag((bool) ($fin['live_fx_enabled'] ?? false)), 'OFF', 'config/financial.php', 'Awaiting Management decision', 'Restricted — kept OFF', null, 'Environment (FINANCIAL_LIVE_FX_ENABLED)', false, '/financial/currency'),
            $e('Ledger', 'ledger', 'Ledger recording', $this->flag((bool) ($fin['ledger_enabled'] ?? false)), 'OFF', 'config/financial.php', 'Awaiting Management decision', 'Environment-controlled', null, 'Environment (FINANCIAL_LEDGER_ENABLED)', false, '/financial/transactions', 'The ledger design and its activation need Management/engineering sign-off before use.'),
            $e('Deposits', 'separate_deposit_approval', 'Separate person approves deposits', $this->flag((bool) ($fin['separate_deposit_approval'] ?? false)), 'OFF', 'config/financial.php', 'Awaiting Management decision', 'Environment-controlled', null, 'Environment (FINANCIAL_SEPARATE_DEPOSIT_APPROVAL)', true, '/admin/deposits'),
            $e('Deposits', 'legacy_deposit_with_plan', 'New deposit-with-plan approvals (legacy flow freeze)', (\App\Support\FundingSource::legacyDepositPolicy()['valid'] ? (\App\Support\FundingSource::legacyDepositPolicy()['value'] === 'block' ? 'FROZEN' : 'ALLOWED (legacy behaviour)') : 'INVALID VALUE — treated as ALLOWED'), 'ALLOWED', 'config/financial.php (principal-lifecycle safeguard, 18 Nov)', 'Safety control — not a Management decision on principal', (\App\Support\FundingSource::legacyDepositPolicy()['value'] === 'block' ? 'ACTIVE (freeze on)' : 'OFF (unchanged behaviour)'), null, 'Environment (FINANCIAL_LEGACY_DEPOSIT_WITH_PLAN=allow|block)', true, '/financial/investment-reconciliation', 'Does not activate the documented target model and changes no balance; it only stops NEW legacy-dual positions while D1 is pending.'),
            $e('Sessions', 'token_lifetime', 'Session lifetime (staff 480 min / investor 10,080 min)', 'Provisional', 'Provisional', 'config/ssi_auth.php (D-02)', 'Awaiting Management decision (D1)', 'Enforced', null, 'Environment', false, '/admin/global', 'These lifetimes are NOT Management-approved.'),
        ];
        foreach (['Investment rules', 'Maturity & payout rules', 'Loyalty & reinvestment', 'Financial approval limits (per role)', 'Notifications & communication policy'] as $c) {
            $items[] = $e($c, \Illuminate\Support\Str::slug($c), $c, 'No Management decision on file', 'None', 'SSI_MANAGEMENT_DECISIONS_REQUIRED.md', 'Awaiting Management decision', 'INACTIVE', null, 'Not configurable until decided', true, '/admin/global');
        }

        return response()->json(['items' => $items, 'read_only' => true,
            'note' => 'Review catalogue. Recommendations are not approvals. Change history for each item is on the page linked in "where".']);
    }

    /** Read-only staff & role visibility. No credentials or tokens are ever returned. */
    public function staff()
    {
        $rows = User::where('role', '!=', 'investor')->orderBy('role')->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'status', 'last_login_at', 'created_at'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'status' => $u->status,
                'last_login_at' => optional($u->last_login_at)->toIso8601String(), 'created_at' => optional($u->created_at)->toIso8601String()])->values();
        return response()->json(['staff' => $rows, 'read_only' => true, 'counts' => $rows->groupBy('role')->map->count(),
            'note' => 'Staff are created by an artisan command. Creating or changing staff roles from the website is awaiting a Management decision on who may assign roles.']);
    }
}
