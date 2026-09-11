<?php
// LOCATION: database/seeders/TaskTypeSeeder.php
//
// Seeds a starter set of task types so the Admin Task Management UI has
// something to select from immediately. Administrators can add, edit, or
// deactivate types afterward from the Admin Panel — no redeploy required.

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TaskType;

class TaskTypeSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['key' => 'activity',   'label' => 'General Activity', 'icon' => '📋', 'requires_amount' => false],
            ['key' => 'signal',     'label' => 'Investment Signal', 'icon' => '📈', 'requires_amount' => true],
            ['key' => 'game',       'label' => 'Game / Challenge',  'icon' => '🎮', 'requires_amount' => false],
            ['key' => 'investment', 'label' => 'Investment Task',   'icon' => '💰', 'requires_amount' => true],
            ['key' => 'custom',     'label' => 'Custom Task',       'icon' => '⚙️', 'requires_amount' => false],
        ];

        foreach ($defaults as $type) {
            TaskType::firstOrCreate(['key' => $type['key']], array_merge($type, ['is_active' => true]));
        }
    }
}
