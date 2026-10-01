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
        //
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
        'financial.communicate',         // message individual investors
        'financial.issue-notices',       // multi-recipient notices, News Centre notice
        'financial.view-audit',          // read the audit trail
    ];
    foreach ($financialAbilities as $ability) {
        Gate::define($ability, fn (User $user) => in_array($user->role, ['financial', 'admin'], true));
    }

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