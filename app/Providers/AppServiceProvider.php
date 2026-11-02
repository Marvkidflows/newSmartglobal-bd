<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Gate;
use App\Models\User;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One shared registry so developers can add rank-criteria fields at runtime
        // (see RankFieldRegistry::register) and every service sees the same list.
        $this->app->singleton(\App\Services\Rank\RankFieldRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
  
public function boot(): void
{
    // ── Financial Team abilities ──────────────────────────────────────────
    // FinancialMiddleware only answers "is this a financial/admin user?".
    // These gates answer "may they do THIS?" and are checked inside the
    // controllers (Gate::authorize), so a route accidentally mounted under
    // the wrong group still cannot perform a sensitive action. Kept as
    // separate abilities so a future role split (e.g. read-only auditor)
    // is a one-line change here rather than a controller rewrite.
    // Admins pass every financial gate; they retain overall control.
    $financialAbilities = [
        'financial.view-records',        // investor financial overview
        'financial.review-transactions', // transaction list/detail
        'financial.act-on-transactions', // approve / reject / hold
        'financial.adjust-balance',      // wallet add / deduct only
        'financial.correct-records',     // investment amount / ROI corrections
        'financial.verify-currency',     // record a record's currency + USD value (rank thresholds are in USD)
        'financial.manage-positions',    // flags, principal release/return/payout/correction, withdrawal classification (Financial Team 27 Oct 2026)
        'financial.reconcile-currency',  // run and resolve the currency reconciliation queue
        'financial.manage-fx-register',  // document an approved exchange-rate source
        'financial.review-currency-policy', // step 1 of the currency policy: review the rules (Financial Team or Admin)
        'financial.communicate',         // message individual investors
        'financial.issue-notices',       // multi-recipient notices, News Centre notice
        'financial.view-audit',          // read the audit trail
    ];
    foreach ($financialAbilities as $ability) {
        Gate::define($ability, fn (User $user) => in_array($user->role, ['financial', 'admin'], true));
    }

    // ── Investor Rank & Badge module ──────────────────────────────────────
    // Admin-only. Kept as separate abilities (same pattern as financial.*) so a
    // future finer-grained split is a one-line change here. Enforced in the
    // controllers/services, not just by hiding buttons.
    foreach ([
        'ranks.view',            // rank overview, investor ranks, criteria, runs
        'ranks.manage-criteria', // create / edit / activate / deactivate / remove criteria
        'ranks.assign',          // manually assign or raise a rank
        'ranks.downgrade',       // manually downgrade, retain on review
        'ranks.override',        // exception to configured criteria (also needs RANKS_ALLOW_MANUAL_OVERRIDE)
        'ranks.view-audit',      // rank + criteria audit history
        'ranks.confirm-currency-policy', // step 2 of the currency policy: confirm / withdraw it (Administrator only)
    ] as $rankAbility) {
        Gate::define($rankAbility, fn (User $user) => $user->role === 'admin');
    }

    // Approval + reporting are shared with the Financial Team (they verify the financial basis of a rank).
    // Configuration, assignment, suspension and revocation stay Admin-only.
    foreach (['ranks.review', 'ranks.export'] as $sharedRankAbility) {
        Gate::define($sharedRankAbility, fn (User $user) => in_array($user->role, ['financial', 'admin'], true));
    }
    foreach (['ranks.suspend', 'ranks.revoke'] as $adminOnlyRankAbility) {
        Gate::define($adminOnlyRankAbility, fn (User $user) => $user->role === 'admin');
    }

    // Rank BENEFITS & lending pathway (Investor Rank, Benefits and Privileges Framework §7). Admin only: financial decisions,
    // loan approvals and changes to investment terms must be limited to appropriately authorised personnel. These endpoints
    // also sit behind the `admin` route middleware, so the Financial Team has NO access to them at all (they keep their own
    // rank-approvals area); the gates are a second, independent check.
    foreach ([
        'ranks.manage-benefits',  // edit wording / dates / publication, suspend, create, prepare incentive terms
        'ranks.confirm-policy',           // record the formal sign-offs that unlock money rank criteria
        'ranks.approve-benefits', // switch a benefit on; approve / activate an incentive term (written approval reference required)
        'loans.configure',        // authorise / activate the lending programme, set eligible ranks
        'loans.review',           // view the programme and applications
        'loans.decide',           // start review, approve, reject
    ] as $benefitAbility) {
        Gate::define($benefitAbility, fn (User $user) => $user->role === 'admin');
    }

    // Evaluation triggers: only events that can change rank eligibility, and only
    // for the ONE affected investor. The job is queued after commit, de-duplicated
    // per investor, and wrapped in rescue() so a ranking problem can never break
    // the deposit / investment / KYC flow that raised the event. READ-ONLY toward
    // money: evaluation never writes to balances or transactions.
    $queueRankEvaluation = function (?int $userId, string $trigger): void {
        if (!$userId) return;
        rescue(fn () => \App\Jobs\EvaluateInvestorRankJob::dispatch($userId, $trigger)->afterCommit(), null, true);
    };

    \App\Models\InvestmentAccount::saved(function ($investment) use ($queueRankEvaluation) {
        if ($investment->wasRecentlyCreated || $investment->wasChanged(['amount', 'status', 'investment_plan_id'])) {
            $queueRankEvaluation($investment->user_id, 'investment_changed');
        }
    });

    \App\Models\Deposit::saved(function ($deposit) use ($queueRankEvaluation) {
        if ($deposit->status === 'approved' && ($deposit->wasRecentlyCreated || $deposit->wasChanged(['status', 'amount']))) {
            $queueRankEvaluation($deposit->user_id, 'deposit_approved');
        }
    });

    // Board §1 / Financial Team §3: re-assess after a verified, material change to eligible principal. Queued and de-duplicated per investor
    // by the same job; the engine only READS these records, it never writes to them.
    \App\Models\Withdrawal::saved(function ($withdrawal) use ($queueRankEvaluation) {
        if ($withdrawal->wasRecentlyCreated || !$withdrawal->wasChanged('status')) return;
        if (in_array($withdrawal->status, ['approved', 'rejected', 'hold'], true)) $queueRankEvaluation((int) $withdrawal->user_id, 'withdrawal_' . $withdrawal->status);
    });
    \App\Models\InvestmentFlag::created(function ($flag) use ($queueRankEvaluation) {
        $uid = \App\Models\InvestmentAccount::whereKey($flag->investment_id)->value('user_id');
        if ($uid) $queueRankEvaluation((int) $uid, 'investment_flag_' . $flag->action);
    });
    \App\Models\PrincipalMovement::created(function ($m) use ($queueRankEvaluation) {
        $queueRankEvaluation((int) $m->user_id, 'principal_' . $m->kind);
    });
    \App\Models\FxReconciliationItem::updated(function ($item) use ($queueRankEvaluation) {
        // Affected ranks are recalculated only AFTER a correction is verified and approved (Financial §5): i.e. when the item is resolved.
        if (!$item->wasChanged('status') || $item->status !== 'resolved') return;
        $uid = $item->subject_type === 'deposit'
            ? \App\Models\Deposit::whereKey($item->subject_id)->value('user_id')
            : \App\Models\InvestmentAccount::whereKey($item->subject_id)->value('user_id');
        if ($uid) $queueRankEvaluation((int) $uid, 'reconciliation_resolved');
    });

    User::updated(function ($user) use ($queueRankEvaluation) {
        // kyc_* and status are rank PREREQUISITES: verification can unlock an upgrade; a suspension
        // or freeze must flag the investor for Admin review (never an automatic downgrade).
        if ($user->role === 'investor' && $user->wasChanged(['kyc_status', 'kyc_verified', 'status'])) {
            $queueRankEvaluation($user->id, 'kyc_changed');
        }
    });

    Mail::extend('brevo', function (array $config) {
        $factory = new BrevoTransportFactory();

        return $factory->create(new Dsn(
            'brevo+api',
            'default',
            $config['key'] ?? null
        ));
    });
}
}