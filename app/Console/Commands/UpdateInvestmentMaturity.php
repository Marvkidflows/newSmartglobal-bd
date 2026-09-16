<?php
namespace App\Console\Commands;

use App\Models\InvestmentAccount;
use Illuminate\Console\Command;

class UpdateInvestmentMaturity extends Command
{
    protected $signature = 'investments:update-maturity';
    protected $description = 'Update investment remaining days and mark completed investments';

    public function handle()
    {
        $investments = InvestmentAccount::where('status', 'active')->get();

        $updated   = 0;
        $completed = 0;

        foreach ($investments as $investment) {
            // FIXED — the model has no updateRemainingDays() method; the
            // real method is syncRemainingDays(). This line was throwing
            // a fatal error every run, so the countdown never updated.
            $investment->syncRemainingDays();
            $updated++;

            // ADDED — syncRemainingDays() only updates the day count; it
            // never flipped a matured investment to 'completed' or paid
            // out the profit. That only happened if an admin manually
            // clicked "Complete" in the dashboard. This makes maturity
            // actually finish the investment on its own, same as the
            // admin's manual complete() action.
            if ($investment->live_remaining_days <= 0) {
                $investment->update(['status' => 'completed']);

                $user = $investment->user;
                if ($user) {
                    $user->increment('balance', $investment->expected_profit ?? 0);
                }

                $completed++;
            }
        }

        $this->info("Investment maturity updated: {$updated} refreshed, {$completed} completed and paid out.");
    }
}