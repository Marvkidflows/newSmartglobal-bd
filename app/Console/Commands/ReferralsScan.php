<?php
// Task 5: backfills Pending reward rows for referred investors who completed registration before this feature existed, then re-assesses
// every Pending reward's eligibility. It NEVER approves or credits anything and never touches a balance. Scheduled hourly with --no-backfill (routes/console.php); the backfill is a manual administrator action:  php artisan referrals:scan
namespace App\Console\Commands;

use App\Models\ReferralReward;
use App\Models\User;
use App\Services\Task5\ReferralRewardService;
use Illuminate\Console\Command;

class ReferralsScan extends Command
{
    protected $signature = 'referrals:scan {--no-backfill : Only re-assess existing rows}';
    protected $description = 'Backfill pending referral reward rows and re-assess eligibility (read-only toward money; approves/credits nothing).';

    public function handle(ReferralRewardService $svc): int
    {
        $created = 0;
        if (!$this->option('no-backfill')) {
            User::where('role', 'investor')->whereNotNull('referred_by')->where('registration_completed', true)
                ->whereNotIn('id', ReferralReward::select('referred_id'))->orderBy('id')->each(function (User $u) use ($svc, &$created) {
                    if ($svc->createForReferred($u)) $created++;
                });
        }
        $checked = 0;
        ReferralReward::where('status', 'pending')->orderBy('id')->each(function (ReferralReward $r) use ($svc, &$checked) { $svc->evaluate($r, null, true); $checked++; });
        $this->info("Backfilled {$created} pending reward row(s); re-assessed {$checked} pending reward(s). Nothing was approved or credited.");
        return self::SUCCESS;
    }
}
