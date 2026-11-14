<?php
// LOCATION: app/Support/ManagementDefaults.php
//
// THE REGISTER OF DEFAULTS applied while Management decisions are outstanding (instruction of 5 Nov 2026: "proceed using documented safe defaults").
// IMPORTANT — using a default is NOT an approval. Every entry carries `approval = NOT APPROVED BY MANAGEMENT`; this file never says otherwise.
//
//   classification:
//     preserved_existing_rule        — the rule that already existed before the remediation is kept unchanged. Nothing new was introduced.
//     temporary_technical_safeguard  — a conservative restriction added by the remediation until Management decides. Replaceable by a recorded decision.
//     documented_risk                — nothing was changed; the risk is documented (e.g. token storage).
//   confirmation:  'required'  = Management/Finance must confirm (or replace) before this is treated as policy.
//                  'optional'  = no change was made to an existing rule; confirm only if you want to change it.
// `php artisan ssi:defaults-report` prints the EFFECTIVE value of each item from the live configuration and `--check` exits non-zero if any
// value differs from the documented safe default (an override then needs a recorded approval).
namespace App\Support;

use App\Services\Financial\BalanceAdjustmentService;

class ManagementDefaults
{
    public const APPROVAL = 'NOT APPROVED BY MANAGEMENT';

    /** @return array<int,array<string,mixed>> */
    public static function register(): array
    {
        $blank = fn ($v) => $v === null || trim((string) $v) === '';
        $cum = app(BalanceAdjustmentService::class)->cumulativeLimit();
        $roles = fn (string $op) => implode(',', (array) config("financial_controls.approver_roles.{$op}", []));
        $onOff = fn ($b) => $b ? 'ON' : 'OFF';

        return [
            ['id' => 1, 'topic' => 'Cumulative adjustment threshold (rolling window per requester)',
             'classification' => 'temporary_technical_safeguard', 'confirmation' => 'required',
             'safe_default' => 'window 24 h; limit = FIN_CUMULATIVE_THRESHOLD_USD if set, else the existing per-adjustment threshold (placeholder 10000.00); if neither is configured the control is DISABLED (no value is invented)',
             'effective' => sprintf('window %d h; per-adjustment threshold %s; cumulative limit %s', (int) config('financial_controls.balance_cumulative_window_hours'),
                 $blank(config('financial_controls.balance_threshold_usd')) ? '(none)' : config('financial_controls.balance_threshold_usd'), $cum ?? 'DISABLED'),
             'ok' => (int) config('financial_controls.balance_cumulative_window_hours') === 24 && $blank(config('financial_controls.balance_cumulative_threshold_usd')),
             'switch' => 'FIN_CUMULATIVE_WINDOW_HOURS, FIN_CUMULATIVE_THRESHOLD_USD, FIN_APPROVAL_THRESHOLD_USD'],

            ['id' => 2, 'topic' => 'Profit adjustment limits', 'classification' => 'preserved_existing_rule', 'confirmation' => 'optional',
             'safe_default' => 'existing validation unchanged (adjustment −100…+100 points; corrected rate 0…1000 %); no new cap introduced',
             'effective' => 'existing validation; optional corrected-rate cap ' . ($blank(config('financial_controls.profit_percentage_cap')) ? '(none)' : config('financial_controls.profit_percentage_cap')),
             'ok' => $blank(config('financial_controls.profit_percentage_cap')), 'switch' => 'FIN_PROFIT_PERCENTAGE_CAP'],

            ['id' => 3, 'topic' => 'Who approves profit adjustments / reversals', 'classification' => 'temporary_technical_safeguard', 'confirmation' => 'required',
             'safe_default' => 'admin only; the approver must be a DIFFERENT active Admin than the requester (hard rule)',
             'effective' => 'profit_adjust: ' . $roles('profit_adjust') . '; profit_reverse: ' . $roles('profit_reverse'),
             'ok' => $roles('profit_adjust') === 'admin' && $roles('profit_reverse') === 'admin', 'switch' => 'FIN_PROFIT_APPROVER_ROLES'],

            ['id' => 4, 'topic' => 'Deposit and withdrawal approvals', 'classification' => 'preserved_existing_rule', 'confirmation' => 'optional',
             'safe_default' => 'existing single-approval workflow preserved; no approval requirement added or removed',
             'effective' => 'single approver (existing); FINANCIAL_SEPARATE_DEPOSIT_APPROVAL=' . $onOff((bool) config('financial.separate_deposit_approval')) . '; withdrawals untouched by this release',
             'ok' => !config('financial.separate_deposit_approval'), 'switch' => 'FINANCIAL_SEPARATE_DEPOSIT_APPROVAL (leave OFF)'],

            ['id' => 5, 'topic' => 'FX valuation and rounding', 'classification' => 'preserved_existing_rule', 'confirmation' => 'required',
             'safe_default' => 'legacy_float (original calculation); a non-USD payment needs a positive rate + source + date — an unknown rate is never assumed to be 1; currency, amount, rate, source, date are preserved',
             'effective' => 'deposit_valuation_mode=' . config('financial.deposit_valuation_mode'),
             'ok' => config('financial.deposit_valuation_mode') === 'legacy_float', 'switch' => 'FINANCIAL_DEPOSIT_VALUATION_MODE'],

            ['id' => 6, 'topic' => 'External images in email rich text', 'classification' => 'temporary_technical_safeguard', 'confirmation' => 'required',
             'safe_default' => 'disabled (embedded raster data: images still work)',
             'effective' => 'external images ' . $onOff((bool) config('privacy.email_external_images')),
             'ok' => !config('privacy.email_external_images'), 'switch' => 'PRIVACY_EMAIL_EXTERNAL_IMAGES'],

            ['id' => 7, 'topic' => 'Authentication token mechanism', 'classification' => 'documented_risk', 'confirmation' => 'required',
             'safe_default' => 'unchanged: Sanctum bearer token in localStorage; NO untested migration; risk documented in SECURITY_REVIEW.md §4',
             'effective' => 'unchanged (bearer token, localStorage)', 'ok' => true, 'switch' => '(none)'],

            ['id' => 8, 'topic' => 'Approval expiry and recovery', 'classification' => 'temporary_technical_safeguard', 'confirmation' => 'required',
             'safe_default' => 'automatic expiry DISABLED; recovery is manual (php artisan ssi:approvals-maintain), not scheduled',
             'effective' => 'expiry ' . ($blank(config('financial_controls.approval_expiry_hours')) ? 'DISABLED' : config('financial_controls.approval_expiry_hours') . ' h') . '; stuck threshold ' . (int) config('financial_controls.stuck_execution_minutes') . ' min',
             'ok' => $blank(config('financial_controls.approval_expiry_hours')), 'switch' => 'FIN_APPROVAL_EXPIRY_HOURS, FIN_STUCK_EXECUTION_MINUTES'],

            ['id' => 9, 'topic' => 'Investment amount / profit-rate corrections', 'classification' => 'temporary_technical_safeguard', 'confirmation' => 'required',
             'safe_default' => 'every correction needs a DIFFERENT authorised approver (admin or financial); protection must not be disabled',
             'effective' => 'second approval ' . $onOff((bool) config('financial_controls.require_approval.investment_correction')) . '; approvers: ' . $roles('investment_correction')
                 . '; master dual-approval switch ' . $onOff((bool) config('financial_controls.dual_approval_enabled')),
             'ok' => (bool) config('financial_controls.require_approval.investment_correction') && (bool) config('financial_controls.dual_approval_enabled') && $roles('investment_correction') === 'admin,financial',
             'switch' => 'FIN_APPROVAL_INVESTMENT_CORRECTION, FIN_DUAL_APPROVAL_ENABLED'],

            ['id' => 10, 'topic' => 'Task LOSS handling', 'classification' => 'preserved_existing_rule', 'confirmation' => 'optional',
             'safe_default' => 'existing rule preserved: a verified loss may take the wallet below zero; applied exactly once, row-locked, with BalanceAdjustment + FinancialAuditLog',
             'effective' => 'unchanged rule; exactly-once key task-assignment-{id}', 'ok' => true, 'switch' => '(code-level: BalanceAdjustmentService::applySigned allowNegative)'],

            ['id' => 11, 'topic' => 'Retry and cancellation of approval requests', 'classification' => 'temporary_technical_safeguard', 'confirmation' => 'required',
             'safe_default' => 'retry: authorised non-requester only, never for requests flagged needs_manual_verification; cancel: the requester or an active Admin, only pending/failed',
             'effective' => 'as documented (code-level)', 'ok' => true, 'switch' => '(code-level: FinancialApprovalService)'],

            ['id' => 12, 'topic' => 'Staff account status', 'classification' => 'temporary_technical_safeguard', 'confirmation' => 'required',
             'safe_default' => 'only ACTIVE staff have access; suspended, frozen, deactivated and any unknown status are refused (investors keep their own restriction model)',
             'effective' => 'allowed staff statuses: ' . implode(',', StaffAccountGuard::ALLOWED_STATUSES),
             'ok' => StaffAccountGuard::ALLOWED_STATUSES === ['active'], 'switch' => '(code-level: StaffAccountGuard::ALLOWED_STATUSES)'],
        ];
    }
}
