<?php
// Investor Rank & Badge module — specification-compliance additions.  PURELY ADDITIVE:
//   • 4 new tables, 2 new nullable/defaulted columns on users.
//   • No existing table is dropped, truncated or rewritten; no existing row is modified.
//   • Every additional criterion is seeded DISABLED (Management approval is pending, spec §3/§11).
//   • Every existing investor keeps their rank: users.rank_status defaults to 'active'.

use App\Services\Rank\AdditionalCriteriaDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. The five optional criteria (definition + on/off switch + Management policy choice) ──
        Schema::create('rank_additional_criteria', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('label', 80);
            $table->text('description')->nullable();
            $table->string('unit', 20)->nullable();
            $table->boolean('is_monetary')->default(false);
            $table->boolean('enabled')->default(false)->index();     // OFF until Management confirms
            $table->json('policy')->nullable();                      // Management's interpretation, where the spec leaves one open
            $table->unsignedBigInteger('policy_set_by')->nullable();
            $table->timestamp('policy_set_at')->nullable();
            $table->unsignedBigInteger('enabled_by')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── 2. Threshold per criterion per rank (kept even while the criterion is disabled) ──
        Schema::create('rank_additional_thresholds', function (Blueprint $table) {
            $table->id();
            $table->string('criterion_key', 40);
            $table->foreignId('investor_rank_id')->constrained('investor_ranks')->cascadeOnDelete();
            $table->decimal('value', 15, 2);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['criterion_key', 'investor_rank_id']);
            $table->foreign('criterion_key')->references('key')->on('rank_additional_criteria')->cascadeOnDelete();
        });

        // ── 3. Append-only history of every enable / disable / threshold / policy change ──
        Schema::create('rank_additional_history', function (Blueprint $table) {
            $table->id();
            $table->string('criterion_key', 40)->index();
            $table->string('action', 20);                 // enabled | disabled | thresholds_changed | policy_set
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 120);
            $table->string('actor_role', 20)->nullable();
            $table->json('previous')->nullable();
            $table->json('new')->nullable();
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // ── 4. Calculated eligibility → pending review → approved / rejected assignment ──
        Schema::create('rank_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->unsignedBigInteger('investor_id')->index();
            $table->string('account_id', 24)->index();
            $table->foreignId('current_rank_id')->nullable()->constrained('investor_ranks');
            $table->foreignId('proposed_rank_id')->constrained('investor_ranks');
            $table->string('status', 12)->default('pending')->index();   // pending | approved | rejected | withdrawn
            // "<investor>:<rank>" while pending, NULL once decided → at most ONE pending review per investor+rank
            $table->string('pending_key', 40)->nullable()->unique();
            // Identifies the rules a rejection applies to, so a rejected proposal is not re-raised every evaluation.
            $table->string('basis_hash', 40)->index();
            $table->unsignedBigInteger('rank_criterion_id')->nullable();
            $table->json('evaluation')->nullable();                      // calculation snapshot at proposal time
            $table->unsignedBigInteger('evaluation_run_id')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->string('decided_by_name', 120)->nullable();
            $table->string('decided_by_role', 20)->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('rank_change_id')->nullable();
            $table->timestamps();
        });

        // ── 5. Rank STATUS (active | suspended | revoked). Badge is shown only while 'active'. ──
        Schema::table('users', function (Blueprint $table) {
            $table->string('rank_status', 12)->default('active')->after('rank_changed_at');
            $table->timestamp('rank_status_changed_at')->nullable()->after('rank_status');
        });

        // ── Seed: five criteria × six ranks, all DISABLED ──
        $now = now();
        $rankIds = DB::table('investor_ranks')->pluck('id', 'key');
        foreach (AdditionalCriteriaDefaults::definitions() as $key => $def) {
            DB::table('rank_additional_criteria')->insert([
                'key' => $key, 'label' => $def['label'], 'description' => $def['description'], 'unit' => $def['unit'],
                'is_monetary' => $def['monetary'], 'enabled' => false, 'sort_order' => $def['sort'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($def['thresholds'] as $rankKey => $value) {
                if (!isset($rankIds[$rankKey])) continue;
                DB::table('rank_additional_thresholds')->insert([
                    'criterion_key' => $key, 'investor_rank_id' => $rankIds[$rankKey], 'value' => $value,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Reverses ONLY what this migration added. Rank assignments (users.investor_rank_id) and the
        // existing audit tables are untouched. Rolling back discards pending reviews and the
        // additional-criteria configuration/history — export them first if they must be kept.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['rank_status', 'rank_status_changed_at']);
        });
        Schema::dropIfExists('rank_reviews');
        Schema::dropIfExists('rank_additional_history');
        Schema::dropIfExists('rank_additional_thresholds');
        Schema::dropIfExists('rank_additional_criteria');
    }
};
