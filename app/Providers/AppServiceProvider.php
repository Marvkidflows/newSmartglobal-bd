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
    ] as $rankAbility) {
        Gate::define($rankAbility, fn (User $user) => $user->role === 'admin');
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