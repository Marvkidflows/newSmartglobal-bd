<?php
// Protects a manual downgrade from being immediately undone by the engine.
// Only created when the investor STILL satisfied the criteria of one or more ranks at
// the moment an Admin lowered them (an authorized exception). Ordinary downgrades
// (investor genuinely fails the rule) never need a hold.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_rank_holds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('investor_id')->index();
            $table->unsignedBigInteger('investor_rank_change_id');   // the downgrade audit record that created it
            // [{rank_id, level, rule_hash}] — ranks automatic upgrade must NOT restore
            $table->json('blocked');
            $table->string('status', 12)->default('active')->index(); // active | released
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_rank_holds');
    }
};
