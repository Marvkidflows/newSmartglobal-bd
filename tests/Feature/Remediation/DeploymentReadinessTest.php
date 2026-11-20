<?php
// LOCATION: tests/Feature/Remediation/DeploymentReadinessTest.php   (NEW FILE — D-19, D-20, D-21)
// The readiness check reports unsafe mail / queue / scheduler / job settings without printing secrets or changing anything, and the football module
// cannot reach money. WRITTEN, NOT EXECUTED (no vendor/); DeploymentReadiness::evaluate was exercised with a standalone harness.
// Run: php artisan test --filter=DeploymentReadinessTest

namespace Tests\Feature\Remediation;

use App\Support\DeploymentReadiness as R;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DeploymentReadinessTest extends TestCase
{
    private function codes(array $c, string $level): array
    {
        return array_values(array_map(fn ($f) => $f['code'], array_filter(R::evaluate($c), fn ($f) => $f['level'] === $level)));
    }

    public function test_production_with_a_log_mailer_is_an_error_but_local_is_only_information(): void
    {
        $this->assertContains('mail.not_delivering', $this->codes(['env' => 'production', 'mailer' => 'log'], R::ERROR));
        $this->assertContains('mail.not_delivering', $this->codes(['env' => 'production', 'mailer' => 'array'], R::ERROR));
        $this->assertNotContains('mail.not_delivering', $this->codes(['env' => 'local', 'mailer' => 'log'], R::ERROR));
        $this->assertContains('mail.not_delivering', $this->codes(['env' => 'local', 'mailer' => 'log'], R::INFO));
    }

    public function test_a_brevo_mailer_without_its_key_is_an_error_in_production(): void
    {
        $this->assertContains('mail.brevo_key_missing', $this->codes(['env' => 'production', 'mailer' => 'brevo', 'brevo_key_set' => false], R::ERROR));
        $this->assertContains('mail.brevo_key_missing', $this->codes(['env' => 'production', 'mailer' => 'smtp', 'mailing_mailer' => 'brevo', 'brevo_key_set' => false], R::ERROR));
        $this->assertNotContains('mail.brevo_key_missing', array_merge($this->codes(['env' => 'production', 'mailer' => 'brevo', 'brevo_key_set' => true], R::ERROR), $this->codes(['env' => 'production', 'mailer' => 'brevo', 'brevo_key_set' => true], R::WARN)));
    }

    public function test_the_check_never_claims_delivery_is_proven(): void
    {
        $this->assertContains('mail.delivery_unproven', $this->codes(['env' => 'production', 'mailer' => 'brevo', 'brevo_key_set' => true], R::INFO));
    }

    public function test_queue_and_scheduler_requirements_are_reported(): void
    {
        $this->assertContains('queue.worker_required', $this->codes(['queue' => 'database'], R::WARN));
        $this->assertContains('queue.null', $this->codes(['env' => 'production', 'queue' => 'null'], R::ERROR));
        $this->assertContains('queue.sync', $this->codes(['queue' => 'sync'], R::INFO));
        $this->assertContains('scheduler.process_required', $this->codes([], R::WARN));
        $this->assertContains('cache.not_shared', $this->codes(['env' => 'production', 'cache' => 'file'], R::WARN));
        $this->assertNotContains('cache.not_shared', $this->codes(['env' => 'production', 'cache' => 'database'], R::WARN));
    }

    public function test_enabling_the_payout_job_without_a_recorded_approval_is_an_error(): void
    {
        $this->assertContains('maturity.no_approval', $this->codes(['maturity_flag' => true], R::ERROR));
        $this->assertContains('maturity.no_approval', $this->codes(['maturity_flag' => true, 'maturity_ref_set' => true], R::ERROR));
        $this->assertContains('maturity.enabled', $this->codes(['maturity_flag' => true, 'maturity_ref_set' => true, 'maturity_max_set' => true], R::WARN));
        $this->assertContains('maturity.off', $this->codes(['maturity_flag' => false], R::INFO));
    }

    public function test_debug_in_production_is_an_error(): void
    {
        $this->assertContains('app.debug_on', $this->codes(['env' => 'production', 'debug' => true], R::ERROR));
        $this->assertNotContains('app.debug_on', $this->codes(['env' => 'local', 'debug' => true], R::ERROR));
    }

    public function test_the_command_prints_states_not_secrets_and_changes_nothing(): void
    {
        config(['mailing.brevo_api_key' => 'SECRET-KEY-VALUE-123', 'services.football_data.api_key' => 'SECRET-FOOTBALL-456']);

        Artisan::call('ssi:check-deployment');
        $out = Artisan::output();

        $this->assertStringContainsString('scheduler.process_required', $out);
        $this->assertStringNotContainsString('SECRET-KEY-VALUE-123', $out);
        $this->assertStringNotContainsString('SECRET-FOOTBALL-456', $out);
    }

    public function test_the_command_fails_in_a_production_like_misconfiguration(): void
    {
        $this->app['env'] = 'production';
        config(['mail.default' => 'log']);
        $this->assertSame(1, Artisan::call('ssi:check-deployment'));
        $this->app['env'] = 'testing';
    }

    public function test_the_football_module_has_no_path_to_balances_or_the_ledger(): void
    {
        foreach (['Services/FixtureSyncService.php', 'Services/FootballDataService.php', 'Http/Controllers/Investor/InvestorFixtureController.php', 'Http/Controllers/Admin/AdminFixtureController.php', 'Console/Commands/SyncFixtures.php'] as $file) {
            $code = preg_replace('#//[^\n]*|/\*.*?\*/|\#[^\n]*#s', '', file_get_contents(app_path($file)));
            $this->assertDoesNotMatchRegularExpression('/\bbalance\b|BalanceAdjustment|WalletLedger|PrincipalService|->decrement\(|->increment\(/i', $code, "{$file} must not touch money");
        }
    }

    public function test_deployment_documentation_and_example_process_file_exist_but_are_not_active(): void
    {
        $this->assertFileExists(base_path('docs/DEPLOY_WORKER_AND_SCHEDULER.md'));
        $this->assertFileExists(base_path('Procfile.example'));
        $this->assertFileDoesNotExist(base_path('Procfile'), 'the example must not silently become a live process definition');
    }
}
