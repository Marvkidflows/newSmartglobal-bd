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

        foreach ($investments as $investment) {
            $investment->updateRemainingDays();
        }

        $this->info('Investment maturity updated successfully!');
    }
}