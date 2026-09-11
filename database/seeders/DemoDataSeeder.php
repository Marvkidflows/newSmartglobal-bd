<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Task;
use App\Models\TaskType;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run()
    {
        // Create admin
        $admin = User::create([
            'name' => 'System Admin',
            'email' => 'adminsystem@gmail.com',
            'password' => Hash::make('password@123'),
            'role' => 'admin',
            'tier' => 'starter', // tier is investment-plan concept; admin doesn't need 'admin' here — role already covers access
            'balance' => 0,
        ]);

        // Create demo investor
        $investor = User::create([
            'name' => 'Demo Investor',
            'email' => 'demo@investor.com',
            'password' => Hash::make('demo123'),
            'role' => 'investor',
            'tier' => 'elite',
            'balance' => 42850.50,
        ]);

        // Create task types (required FK on shared_tasks — must exist first)
        $surveyType = TaskType::create([
            'key' => 'activity',
            'label' => 'Activity',
            'icon' => '📝',
            'description' => 'Simple engagement activities',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $securityType = TaskType::create([
            'key' => 'security',
            'label' => 'Security',
            'icon' => '🔒',
            'description' => 'Account security tasks',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $referralType = TaskType::create([
            'key' => 'referral',
            'label' => 'Referral',
            'icon' => '🔗',
            'description' => 'Referral and sharing tasks',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        // Create sample shared tasks
        // NOTE: 'reward' no longer exists on this table. If you want a fixed
        // payout amount per task, that's a product decision — either add a
        // column back (e.g. `reward_amount`) or model it via
        // `required_amount` / `amount_received` on task_assignments once
        // an investor is actually assigned.
        Task::create([
            'task_code' => 'SURVEY-SENTIMENT-01',
            'task_type_id' => $surveyType->id,
            'title' => 'Market Sentiment Survey',
            'description' => 'Share your market outlook for this week',
            'status' => 'active',
            'activates_at' => now(),
            'expires_at' => now()->addWeek(),
            'created_by' => $admin->id,
        ]);

        Task::create([
            'task_code' => 'SECURITY-EMAIL-01',
            'task_type_id' => $securityType->id,
            'title' => 'Verify Secondary Email',
            'description' => 'Add backup email for account security',
            'status' => 'active',
            'activates_at' => now(),
            'expires_at' => now()->addWeek(),
            'created_by' => $admin->id,
        ]);

        Task::create([
            'task_code' => 'REFERRAL-SHARE-01',
            'task_type_id' => $referralType->id,
            'title' => 'Share Referral Link',
            'description' => 'Invite friends to earn bonus rewards',
            'status' => 'active',
            'activates_at' => now(),
            'expires_at' => now()->addWeek(),
            'created_by' => $admin->id,
        ]);
    }
}