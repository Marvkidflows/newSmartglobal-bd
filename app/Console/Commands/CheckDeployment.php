<?php
// LOCATION: app/Console/Commands/CheckDeployment.php   (NEW FILE — D-19, D-20)
namespace App\Console\Commands;

use App\Support\DeploymentReadiness;
use Illuminate\Console\Command;

/** php artisan ssi:check-deployment — READ ONLY. Prints variable names and states, never secret values. Exit code 1 if any ERROR. */
class CheckDeployment extends Command
{
    protected $signature = 'ssi:check-deployment';
    protected $description = 'Read-only check of mail, queue, scheduler and scheduled-job settings (no secrets printed, nothing sent or changed)';

    public function handle(): int
    {
        $findings = DeploymentReadiness::evaluate([
            'env'              => app()->environment(),
            'debug'            => (bool) config('app.debug'),
            'mailer'           => (string) config('mail.default'),
            'mailing_mailer'   => (string) config('mailing.mailer', env('MAILING_MAILER', '')),
            'brevo_key_set'    => trim((string) config('mailing.brevo_api_key')) !== '',
            'queue'            => (string) config('queue.default'),
            'cache'            => (string) config('cache.default'),
            'maturity_flag'    => (bool) config('scheduling.investment_maturity'),
            'maturity_ref_set' => trim((string) config('scheduling.investment_maturity_approval_ref')) !== '',
            'maturity_max_set' => is_numeric(config('scheduling.investment_maturity_max_run_total')) && (float) config('scheduling.investment_maturity_max_run_total') > 0,
            'fixture_flag'     => (bool) config('scheduling.fixture_sync'),
            'fixture_key_set'  => trim((string) config('services.football_data.api_key')) !== '',
        ]);
        foreach ($findings as $x) {
            $line = strtoupper($x['level']) . '  [' . $x['code'] . ']  ' . $x['message'];
            $x['level'] === DeploymentReadiness::ERROR ? $this->error($line) : ($x['level'] === DeploymentReadiness::WARN ? $this->warn($line) : $this->line($line));
        }
        return DeploymentReadiness::hasErrors($findings) ? self::FAILURE : self::SUCCESS;
    }
}
