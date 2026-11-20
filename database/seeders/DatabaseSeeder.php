<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * D-01: demo accounts and demo data are created ONLY in a local development environment (config/seeding.php). In production, staging or any
     * unknown APP_ENV this seeds reference data only (task types, which uses firstOrCreate), so `db:seed` can never create a default administrator
     * or a demo investor, and can never touch an existing account. Sample investment plans carry profit percentages, so they are demo data too and
     * are not seeded outside development; real plans are created by an administrator.
     */
    public function run(): void
    {
        if (app()->environment((array) config('seeding.demo_environments', []))) {
            // DemoDataSeeder additionally refuses (loudly, before writing) if the database is not on this machine.
            $this->call([
                DemoDataSeeder::class,
                InvestmentPlanSeeder::class,
                TaskTypeSeeder::class,
            ]);
            return;
        }

        $this->command?->warn('APP_ENV is "' . (app()->environment() ?: 'empty') . '": demo accounts and demo data are NOT created. Seeding reference data only.');
        $this->call([
            TaskTypeSeeder::class,
        ]);
    }
}
