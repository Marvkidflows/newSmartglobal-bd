<?php
// LOCATION: app/Console/Commands/MaintainFinancialApprovals.php
//
// R8 recovery/expiry for the second-approval workflow. NOT scheduled automatically — run it by hand or add it to the scheduler once
// Management has decided the expiry policy (decision #8).
//   expire  : marks overdue PENDING requests 'expired'. No-op unless FIN_APPROVAL_EXPIRY_HOURS is set (requests then carry an expires_at).
//   recover : requests left 'executing' / legacy 'approved' for longer than FIN_STUCK_EXECUTION_MINUTES are reconciled by looking for the
//             execution's own records; it never guesses about money and never re-executes anything (retry is a deliberate human action).
// --dry-run lists what WOULD be touched and writes nothing.
namespace App\Console\Commands;

use App\Models\FinancialApprovalRequest as Req;
use App\Services\Financial\FinancialApprovalService;
use Illuminate\Console\Command;

class MaintainFinancialApprovals extends Command
{
    protected $signature = 'ssi:approvals-maintain {--dry-run : List only; change nothing}';
    protected $description = 'Expire overdue approval requests and reconcile stuck (executing / legacy approved) ones. Never moves money.';

    public function handle(FinancialApprovalService $svc): int
    {
        $mins = max(1, (int) config('financial_controls.stuck_execution_minutes', 15));
        $overdue = Req::where('status', Req::PENDING)->whereNotNull('expires_at')->where('expires_at', '<', now())->count();
        $stuck = Req::whereIn('status', [Req::EXECUTING, Req::LEGACY_APPROVED])->where('updated_at', '<', now()->subMinutes($mins))->pluck('id')->all();
        $this->info("Overdue pending requests: {$overdue}; stuck requests (> {$mins} min): " . count($stuck) . ($stuck ? ' [ids ' . implode(',', $stuck) . ']' : ''));
        if ($this->option('dry-run')) { $this->comment('Dry run — nothing changed.'); return self::SUCCESS; }

        $this->info('Expired: ' . $svc->expireDue());
        foreach ($svc->recoverStuck($mins) as $r) $this->line("  #{$r['id']} was {$r['from']}: {$r['outcome']}");
        return self::SUCCESS;
    }
}
