<?php
// LOCATION: app/Console/Commands/SendRankProgressNotices.php
// Daily sweep (see routes/console.php). Reads only; the single side effect is a notification to the investor, and only when the service's
// rules allow it. It never changes a rank, a balance or a review.

namespace App\Console\Commands;

use App\Jobs\SendRankProgressNoticesJob;
use App\Models\User;
use App\Services\Rank\RankProgressNoticeService;
use Illuminate\Console\Command;

class SendRankProgressNotices extends Command
{
    protected $signature = 'ranks:progress-notices {--sync : Process in this run instead of queueing} {--user= : Only this investor id}';
    protected $description = 'Send "approaching the next rank" notices to ranked investors (verified progress only; never changes a rank).';

    public function handle(RankProgressNoticeService $notices): int
    {
        if (!$notices->enabled()) {
            $this->info('Progress notices are disabled (ranks.progress_notices.enabled).');
            return self::SUCCESS;
        }

        $query = User::query()->where('role', 'investor')->whereNotNull('investor_rank_id')
            ->where(fn ($q) => $q->whereNull('rank_status')->orWhere('rank_status', 'active'))
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'));
        if ($this->option('user')) $query->whereKey((int) $this->option('user'));

        $chunk = max(1, (int) config('ranks.progress_notices.chunk', 100));
        $seen = 0; $sent = 0; $errors = 0;

        $query->chunkById($chunk, function ($users) use ($notices, &$seen, &$sent, &$errors) {
            $ids = $users->pluck('id')->all();
            $seen += count($ids);

            if (!$this->option('sync')) {
                SendRankProgressNoticesJob::dispatch($ids);
                return;
            }
            foreach ($users as $u) {
                try {
                    if ($notices->process($u)['status'] === 'sent') $sent++;
                } catch (\Throwable $e) {
                    $errors++;
                    report($e);
                }
            }
        });

        $this->info($this->option('sync')
            ? "Examined {$seen} investor(s); sent {$sent} notice(s); {$errors} error(s)."
            : "Queued {$seen} investor(s) for progress notices.");
        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
