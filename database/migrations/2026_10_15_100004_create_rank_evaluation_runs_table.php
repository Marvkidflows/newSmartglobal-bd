<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('trigger', 30);                       // criteria_change | scheduled | manual
            $table->unsignedBigInteger('investor_rank_id')->nullable(); // rank whose criterion changed (scope)
            $table->unsignedTinyInteger('target_level')->nullable();    // only investors BELOW this level are in scope
            $table->unsignedBigInteger('rank_criterion_id')->nullable();
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->string('status', 24)->default('pending')->index(); // pending|running|completed|completed_with_errors|failed
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('upgraded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedBigInteger('cursor_user_id')->default(0);
            $table->json('failures')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_evaluation_runs');
    }
};
