<?php
// LOCATION: app/Services/Rank/BenefitDefaults.php
//
// DATA ONLY. The benefits and privileges of the six ranks, transcribed from the "SSI Investor Rank, Benefits and
// Privileges Framework" (§3 A–F, §5). The framework describes them as a PROPOSAL "for development and management
// review", so the INITIAL statuses are deliberately conservative — nothing financial and nothing that needs SSI staff
// action is switched on:
//
//   active                 only features the application itself already delivers and that were verified in the code
//                          (rank badge, rank progress, cycle counter, notifications, standard dashboard / records);
//   subject_to_eligibility available once the investor independently qualifies / consents (products, reinvestment
//                          options, optional recognition);
//   pending_approval       everything delivered by SSI staff, every reporting upgrade not built yet, every loan
//                          advantage, every financial incentive — until SSI management approves it in the admin panel.
//
// No percentage, rate, limit, fee or amount appears here, and none may be added: the framework forbids inventing
// financial terms. Management edits wording/status in Investor Ranks → Benefits (audited); the sync NEVER overwrites
// an existing row (RankBenefitService::syncDefaults inserts missing keys only).

namespace App\Services\Rank;

class BenefitDefaults
{
    /** Per-rank text that is NOT a benefit row: the framework's "ROI treatment" statement. Display only. */
    public const ROI_TREATMENT = [
        'bronze'   => 'The return specified in the applicable investment plan applies. Rank alone does not guarantee an additional return.',
        'silver'   => 'A loyalty benefit or enhanced plan rate may apply only where expressly authorized by the relevant investment terms.',
        'gold'     => 'Plan-specific incentives may be offered only after approval. Loyalty bonuses are shown separately from the underlying investment return, with their conditions and payment status.',
        'platinum' => 'A distinct benefit may be attached to an expressly approved plan. Rank does not automatically alter a contractual return.',
        'diamond'  => 'Enhanced returns or loyalty benefits must be tied to a defined, approved product or incentive programme — not an unrestricted rank-based promise.',
        'elite'    => 'Elite investors may be considered for the highest tier of approved loyalty incentives or eligible plan-specific benefits. Any preferential rate requires documented approval, risk disclosure and applicable regulatory review.',
    ];

    public const LOAN_DISCLAIMER = 'Rank grants eligibility to apply for consideration, not approval. Lending terms, affordability, collateral and default procedures require a separately approved policy. No specific interest rate, unsecured facility or borrowing limit is guaranteed.';

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        $rows = [];
        $n = [];   // running display order per rank
        $add = function (string $rank, string $slug, string $cat, string $title, string $desc, string $status, string $fulfil, array $o = []) use (&$rows, &$n) {
            $n[$rank] = ($n[$rank] ?? 0) + 10;
            $rows[] = [
                'key' => "$rank.$slug", 'rank' => $rank, 'category' => $cat, 'title' => $title, 'description' => $desc,
                'status' => $status, 'fulfilment' => $fulfil,
                'is_financial'      => $o['financial'] ?? false,
                'requires_approval' => $o['approval'] ?? ($fulfil !== 'platform' || ($o['financial'] ?? false)),
                'requires_consent'  => $o['consent'] ?? false,
                'cumulative'        => $o['cumulative'] ?? true,
                'display_order'     => $n[$rank],
            ];
        };
        $A = 'active'; $E = 'subject_to_eligibility'; $P = 'pending_approval';

        // ── Bronze ────────────────────────────────────────────────────────────────────────────────────────
        $add('bronze', 'badge', 'recognition', 'Official Bronze badge', 'Your Bronze badge is shown beside your name and on your profile and dashboard.', $A, 'platform', ['cumulative' => false]);
        $add('bronze', 'dashboard-records', 'recognition', 'Standard investor dashboard', 'Standard investor dashboard, investment records and transaction history.', $A, 'platform');
        $add('bronze', 'cycle-status', 'recognition', 'Investment-cycle status and account notifications', 'See the status of your investment cycles and receive available account notifications.', $A, 'platform');
        $add('bronze', 'announcements', 'recognition', 'Announcements and educational updates', 'Standard investment announcements and educational updates.', $P, 'operational');
        $add('bronze', 'plan-access', 'service', 'Access to products you qualify for', 'Access to investment products for which you independently qualify.', $E, 'platform');
        $add('bronze', 'standard-support', 'service', 'Standard processing and support', 'Standard processing and customer support under published policies.', $A, 'operational', ['approval' => false]);
        $add('bronze', 'reinvestment-options', 'reinvestment', 'Eligible reinvestment options', 'Access to eligible reinvestment options.', $E, 'operational');
        $add('bronze', 'next-rank-notice', 'service', 'Notice when you meet Silver requirements', 'An automatic notification when you meet the Silver qualification requirements.', $P, 'platform', ['approval' => true]);

        // ── Silver ────────────────────────────────────────────────────────────────────────────────────────
        $add('silver', 'badge', 'recognition', 'Official Silver badge and rank status', 'Your Silver badge and rank status are shown on your profile and dashboard.', $A, 'platform', ['cumulative' => false]);
        $add('silver', 'progress-indicator', 'recognition', 'Rank-progress indicator and cycle summary', 'A personalised indicator of your progress toward the next rank, with a summary of your completed cycles.', $A, 'platform');
        $add('silver', 'upgrade-notifications', 'recognition', 'Rank-upgrade notifications', 'You are notified when your rank changes.', $A, 'platform');
        $add('silver', 'education', 'recognition', 'Investor education and financial updates', 'Eligibility for selected investor education sessions and financial updates.', $P, 'operational');
        $add('silver', 'priority-support', 'service', 'Priority support', 'Priority support over standard support where operational capacity permits.', $P, 'operational');
        $add('silver', 'early-notice', 'service', 'Early notice of plan launches', 'Early notification of eligible investment-plan launches and opportunities.', $P, 'operational');
        $add('silver', 'reinvest-loyalty', 'loyalty', 'Reinvestment options and loyalty benefits', 'Access to approved reinvestment options and published loyalty benefits.', $P, 'operational');
        $add('silver', 'engagement', 'loyalty', 'Investor engagement and recognition programmes', 'Eligibility for approved investor engagement and recognition programmes.', $P, 'operational');
        $add('silver', 'rank-incentive', 'roi', 'Rank-based incentive (inactive)', 'A loyalty benefit or enhanced plan rate, only where expressly authorized by the relevant investment terms. Inactive until SSI management approves specific terms.', $P, 'operational', ['financial' => true]);

        // ── Gold ──────────────────────────────────────────────────────────────────────────────────────────
        $add('gold', 'badge', 'recognition', 'Official Gold badge and rank status', 'Your Gold badge and rank status are shown across the relevant dashboard sections.', $A, 'platform', ['cumulative' => false]);
        $add('gold', 'performance-summary', 'recognition', 'Expanded performance summary and cycle history', 'An expanded investment-performance summary and cycle history.', $P, 'platform', ['approval' => true]);
        $add('gold', 'briefings', 'recognition', 'Investor briefings and education', 'Invitations to eligible investor briefings and educational sessions.', $P, 'operational');
        $add('gold', 'private-recognition', 'recognition', 'Recognition in private investor communications (with consent)', 'Optional recognition in private investor communications. This only happens if you give your consent, and you can withdraw it at any time.', $E, 'operational', ['consent' => true, 'approval' => false]);
        $add('gold', 'priority-announcements', 'service', 'Priority access to plan announcements', 'Priority access to eligible investment-plan announcements.', $P, 'operational');
        $add('gold', 'support-escalation', 'service', 'Enhanced support escalation', 'Enhanced support escalation for unresolved account or transaction issues.', $P, 'operational');
        $add('gold', 'loyalty-rewards', 'loyalty', 'Loyalty rewards and reinvestment incentives', 'Eligibility for approved loyalty rewards and reinvestment incentives.', $P, 'operational');
        $add('gold', 'extra-reporting', 'service', 'Additional information and reporting', 'Additional investment information and reporting features where available.', $P, 'platform', ['approval' => true]);
        $add('gold', 'rank-incentive', 'roi', 'Rank-based incentive (inactive)', 'Plan-specific incentives, offered only after approval and shown separately from the underlying investment return, with conditions and payment status. Inactive until SSI management approves specific terms.', $P, 'operational', ['financial' => true]);

        // ── Platinum ──────────────────────────────────────────────────────────────────────────────────────
        $add('platinum', 'badge', 'recognition', 'Official Platinum badge and status', 'Your Platinum badge and status are shown on your dashboard and profile.', $A, 'platform', ['cumulative' => false]);
        $add('platinum', 'performance-summary', 'recognition', 'Detailed investment and cycle-performance summary', 'A detailed summary of your investment and cycle performance.', $P, 'platform', ['approval' => true]);
        $add('platinum', 'briefings', 'recognition', 'Designated briefings and management sessions', 'Invitations to designated investor briefings and approved management sessions.', $P, 'operational');
        $add('platinum', 'enhanced-reporting', 'service', 'Enhanced reporting and activity summaries', 'Enhanced investment reporting and account activity summaries.', $P, 'platform', ['approval' => true]);
        $add('platinum', 'support-escalation', 'service', 'Higher-priority support escalation', 'Higher-priority support escalation, subject to published service standards.', $P, 'operational');
        $add('platinum', 'early-communication', 'service', 'Early communication about opportunities', 'Early communication about eligible opportunities and plan changes.', $P, 'operational');
        $add('platinum', 'premium-services', 'service', 'Premium investor services', 'Eligibility for approved premium investor services.', $P, 'operational');
        $add('platinum', 'loyalty-programmes', 'loyalty', 'Loyalty and reinvestment programmes', 'Access to management-approved loyalty or reinvestment programmes.', $P, 'operational');
        $add('platinum', 'rank-incentive', 'roi', 'Rank-based incentive (inactive)', 'A distinct benefit may be attached to an expressly approved plan. Inactive until SSI management approves specific terms.', $P, 'operational', ['financial' => true]);

        // ── Diamond ───────────────────────────────────────────────────────────────────────────────────────
        $add('diamond', 'badge', 'recognition', 'Official Diamond badge and status', 'Your Diamond badge and status are shown in the relevant dashboard sections.', $A, 'platform', ['cumulative' => false]);
        $add('diamond', 'enhanced-reporting', 'recognition', 'Enhanced reporting and consolidated cycle records', 'Enhanced investment reporting and consolidated cycle records.', $P, 'platform', ['approval' => true]);
        $add('diamond', 'invitation-briefings', 'recognition', 'Invitation-only briefings and premium engagement', 'Eligibility for invitation-only investor briefings and approved premium engagement.', $P, 'operational');
        $add('diamond', 'relationship-channel', 'recognition', 'Designated relationship-support channel', 'A designated relationship-support channel where operationally available.', $P, 'operational');
        $add('diamond', 'premium-support', 'service', 'Premium support and priority escalation', 'Premium support and priority escalation for eligible account issues.', $P, 'operational');
        $add('diamond', 'early-access', 'service', 'Early access to opportunities', 'Early access to eligible opportunities, subject to availability and suitability.', $P, 'operational');
        $add('diamond', 'premium-loyalty', 'loyalty', 'Premium loyalty and reinvestment incentives', 'Eligibility for approved premium loyalty benefits and reinvestment incentives.', $P, 'operational');
        $add('diamond', 'loan-pathway', 'loan', 'Loan application pathway', 'Access to a dedicated loan application pathway once the lending programme is authorized and activated.', $P, 'platform', ['approval' => false]);
        $add('diamond', 'loan-eligibility', 'loan', 'Eligibility to apply for an investor loan', 'Eligibility to apply for an SSI-approved investor loan or financing programme, if legally authorized. Eligibility is not approval.', $P, 'platform', ['approval' => false]);
        $add('diamond', 'loan-limits', 'loan', 'Potentially higher loan limits', 'Potentially higher loan limits than lower-ranked applicants, subject to approved policy.', $P, 'operational');
        $add('diamond', 'loan-priority-review', 'loan', 'Priority application review', 'Priority application review, subject to verification and lending capacity.', $P, 'operational');
        $add('diamond', 'loan-history-consideration', 'loan', 'Investment history considered', 'Consideration of your verified investment history and completed cycles.', $P, 'operational');
        $add('diamond', 'loan-dashboard', 'loan', 'Loan dashboard', 'A loan dashboard showing application status, approved terms, fees, repayment schedule and outstanding balance.', $P, 'future_feature');
        $add('diamond', 'rank-incentive', 'roi', 'Rank-based incentive (inactive)', 'Enhanced returns or loyalty benefits tied to a defined, approved product or incentive programme. Inactive until SSI management approves specific terms.', $P, 'operational', ['financial' => true]);

        // ── Elite ─────────────────────────────────────────────────────────────────────────────────────────
        $add('elite', 'badge', 'recognition', 'Official Elite badge', 'Your Elite badge is displayed prominently beside your name.', $A, 'platform', ['cumulative' => false]);
        $add('elite', 'highest-reporting', 'recognition', 'Highest tier of reporting and consolidated cycle records', 'The highest tier of investor reporting and consolidated cycle records.', $P, 'platform', ['approval' => true]);
        $add('elite', 'management-briefings', 'recognition', 'Invitation-only management briefings', 'Eligibility for invitation-only management briefings and approved strategic engagement.', $P, 'operational');
        $add('elite', 'relationship-manager', 'recognition', 'Designated relationship manager', 'Eligibility for a designated relationship manager, subject to staffing and service availability.', $P, 'operational');
        $add('elite', 'highest-support', 'service', 'Highest support priority', 'Highest support priority under the approved SSI service framework.', $P, 'operational');
        $add('elite', 'early-notification', 'service', 'Early notification of products and programmes', 'Early notification of eligible products and approved investment programmes.', $P, 'operational');
        $add('elite', 'highest-loyalty', 'loyalty', 'Highest tier of loyalty and reinvestment benefits', 'Eligibility for the highest tier of approved loyalty and reinvestment benefits.', $P, 'operational');
        $add('elite', 'premium-engagement', 'service', 'Premium engagement and reporting services', 'Premium investor engagement and reporting services.', $P, 'operational');
        $add('elite', 'bespoke', 'service', 'Bespoke service arrangements', 'Eligibility for management-approved bespoke service arrangements where permitted.', $P, 'operational');
        $add('elite', 'loan-pathway', 'loan', 'Loan application pathway (highest tier)', 'Access to the highest proposed investor-loan eligibility tier under the approved lending framework, once the lending programme is authorized and activated. Eligibility is not approval.', $P, 'platform', ['approval' => false]);
        $add('elite', 'loan-limits', 'loan', 'Consideration for higher loan limits', 'Consideration for higher potential loan limits than Diamond investors, subject to repayment capacity, verification, collateral and available funds.', $P, 'operational');
        $add('elite', 'loan-priority-assessment', 'loan', 'Priority assessment and loan-support contact', 'Priority application assessment and access to a designated loan-support contact.', $P, 'operational');
        $add('elite', 'loan-negotiated-terms', 'loan', 'Negotiated terms (only where authorized)', 'Negotiated terms only where formally authorized and documented.', $P, 'operational');
        $add('elite', 'loan-dashboard', 'loan', 'Loan-management dashboard', 'A loan-management dashboard covering applications, approved facilities, repayment dates, outstanding obligations and notices.', $P, 'future_feature');
        $add('elite', 'rank-incentive', 'roi', 'Rank-based incentive (inactive)', 'Consideration for the highest tier of approved loyalty incentives or eligible plan-specific benefits. Any preferential rate requires documented approval, risk disclosure and applicable regulatory review. Inactive until SSI management approves specific terms.', $P, 'operational', ['financial' => true]);

        return $rows;
    }
}
