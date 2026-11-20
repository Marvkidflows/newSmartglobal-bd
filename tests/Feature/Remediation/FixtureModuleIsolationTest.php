<?php
// LOCATION: tests/Feature/Remediation/FixtureModuleIsolationTest.php   (NEW FILE — D-21)
// The football fixtures / prediction module is outside every requirement document and Management has not decided its future (decision D5). Until then it must
// stay HARMLESS: it may never read or write money, and it must be switchable off without a code change. WRITTEN, NOT EXECUTED (no vendor/).
// Run: php artisan test --filter=FixtureModuleIsolationTest

namespace Tests\Feature\Remediation;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class FixtureModuleIsolationTest extends TestCase
{
    /** Every source file that belongs to the module. A new file added to it must be listed here, which forces the isolation check to cover it. */
    private const MODULE_FILES = [
        'Models/Fixture.php', 'Models/FixtureMarket.php', 'Models/FixturePrediction.php', 'Models/FixtureSyncLog.php', 'Models/PredictionEntry.php',
        'Models/PredictionRound.php', 'Models/Competition.php', 'Services/FixtureSyncService.php', 'Services/FootballDataService.php',
        'Http/Controllers/Investor/InvestorFixtureController.php', 'Http/Controllers/Investor/InvestorPredictionController.php',
        'Http/Controllers/Admin/AdminPredictionController.php', 'Http/Controllers/Admin/AdminFixtureController.php',
        'Http/Controllers/Admin/AdminCompetitionController.php', 'Console/Commands/SyncFixtures.php',
    ];

    public function test_the_module_never_references_money_in_executable_code(): void
    {
        foreach (self::MODULE_FILES as $file) {
            $source = file_get_contents(app_path($file));
            $code   = preg_replace('#//[^\n]*|/\*.*?\*/|\#[^\n]*#s', '', $source);   // comments may mention "balance" to say it is NOT touched

            $this->assertDoesNotMatchRegularExpression(
                '/\bbalance\b|wallet|Withdrawal|InvestmentAccount|BalanceAdjustment|ProfitAdjustment|LedgerEntry|FinancialSnapshot|Deposit/i',
                $code,
                "app/{$file} must not touch balances, wallets, withdrawals, investments, deposits or the ledger"
            );
        }
    }

    public function test_every_file_listed_exists_and_no_unlisted_fixture_file_has_appeared(): void
    {
        foreach (self::MODULE_FILES as $file) {
            $this->assertFileExists(app_path($file));
        }
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() === 'php' && preg_match('/Fixture|Prediction|Competition|FootballData/', $f->getFilename())) {
                $found[] = str_replace('\\', '/', substr($f->getPathname(), strlen(app_path()) + 1));
            }
        }
        $found = array_values(array_diff($found, ['Support/DeploymentReadiness.php', 'Console/Commands/LoadRankDemoData.php']));   // mention fixtures, are not part of the module
        $this->assertEqualsCanonicalizing(self::MODULE_FILES, $found, 'a new fixture/prediction file exists (or one was removed): update MODULE_FILES so the money-isolation check covers it');
    }

    public function test_the_sync_can_be_switched_off_by_configuration_alone(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'gaming:sync-fixtures'));
        $this->assertNotNull($event);

        config(['scheduling.fixture_sync' => false]);
        $this->assertFalse($event->filtersPass($this->app), 'SCHEDULE_FIXTURE_SYNC=false must stop the scheduled sync');
        $this->assertTrue($event->withoutOverlapping, 'two syncs must never overlap');
        $this->assertTrue($event->onOneServer, 'only one server may run it');
    }

    public function test_the_api_key_comes_from_the_environment_and_is_not_in_the_repository(): void
    {
        $this->assertStringContainsString("'api_key' => env('FOOTBALL_DATA_API_KEY')", file_get_contents(config_path('services.php')));
    }
}
