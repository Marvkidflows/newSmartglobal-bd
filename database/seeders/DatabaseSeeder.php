<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Call your demo data seeder
        $this->call([
            DemoDataSeeder::class,
            InvestmentPlanSeeder::class,
            TaskTypeSeeder::class,
        ]);
    }
}
