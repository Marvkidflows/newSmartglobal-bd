<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One criterion = one rule set for one rank. At most one ACTIVE
        // criterion per rank (enforced in RankCriteriaService under a row
        // lock; MySQL has no partial unique index).
        Schema::create('rank_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_rank_id')->constrained('investor_ranks');
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('logic', 10)->default('all');   // all | any | grouped
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->softDeletes();                          // "remove" = archive; history is retained
            $table->timestamps();
            $table->index(['investor_rank_id', 'is_active']);
        });

        Schema::create('rank_criterion_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rank_criterion_id')->constrained('rank_criteria')->cascadeOnDelete();
            $table->unsignedSmallInteger('group_no')->default(1); // used when logic = grouped
            $table->string('field', 60);
            $table->string('operator', 12);
            $table->json('value');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // Append-only. No FK on purpose: history must outlive anything.
        Schema::create('rank_criteria_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rank_criterion_id')->index();
            $table->unsignedBigInteger('investor_rank_id')->index();
            $table->unsignedInteger('version');
            $table->string('action', 20)->index(); // created|updated|activated|deactivated|archived
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_name', 120);
            $table->json('previous')->nullable();
            $table->json('new')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_criteria_history');
        Schema::dropIfExists('rank_criterion_conditions');
        Schema::dropIfExists('rank_criteria');
    }
};
