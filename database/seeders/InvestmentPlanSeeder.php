<?php

namespace Database\Seeders;

use App\Models\InvestmentPlan;
use Illuminate\Database\Seeder;

class InvestmentPlanSeeder extends Seeder
{
    public function run()
    {
        $plans = [
            [
                'name' => 'Starter Plan',
                'description' => 'Perfect for beginners starting their investment journey',
                'min_amount' => 100,
                'max_amount' => 999,
                'profit_percentage' => 10,
                'duration_months' => 3,
                'status' => 'active',
            ],
            [
                'name' => 'Silver Plan',
                'description' => 'Great for moderate investors looking for steady returns',
                'min_amount' => 1000,
                'max_amount' => 4999,
                'profit_percentage' => 15,
                'duration_months' => 5,
                'status' => 'active',
            ],
            [
                'name' => 'Gold Plan',
                'description' => 'Excellent for experienced investors',
                'min_amount' => 5000,
                'max_amount' => 19999,
                'profit_percentage' => 20,
                'duration_months' => 7,
                'status' => 'active',
            ],
            [
                'name' => 'Platinum Plan',
                'description' => 'Premium investment package for high-value investors',
                'min_amount' => 20000,
                'max_amount' => 999999,
                'profit_percentage' => 25,
                'duration_months' => 12,
                'status' => 'active',
            ],
        ];

        foreach ($plans as $plan) {
            InvestmentPlan::create($plan);
        }
    }
}

