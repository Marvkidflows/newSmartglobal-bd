<?php
// Rank DEFINITIONS only (name / order / visual identity). No qualifying
// thresholds are seeded — criteria are entered by Admin in the dashboard.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_ranks', function (Blueprint $table) {
            $table->id();
            $table->string('key', 20)->unique();
            $table->string('name', 40);
            $table->unsignedTinyInteger('level')->unique(); // 1 (lowest) … 6 (highest)
            $table->string('color', 9);
            $table->string('icon', 30);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        $now = now();
        $ranks = [
            ['bronze',   'Bronze',   1, '#B0713A', 'shield',  'Entry investor classification'],
            ['silver',   'Silver',   2, '#8A97A8', 'shield',  'Second investor classification'],
            ['gold',     'Gold',     3, '#C9A84C', 'award',   'Third investor classification'],
            ['platinum', 'Platinum', 4, '#5B7C99', 'star',    'Fourth investor classification'],
            ['diamond',  'Diamond',  5, '#2FA7D9', 'diamond', 'Fifth investor classification'],
            ['elite',    'Elite',    6, '#6D28D9', 'crown',   'Highest investor classification'],
        ];
        foreach ($ranks as [$key, $name, $level, $color, $icon, $desc]) {
            DB::table('investor_ranks')->insert([
                'key' => $key, 'name' => $name, 'level' => $level, 'color' => $color,
                'icon' => $icon, 'description' => $desc, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_ranks');
    }
};
