<?php
// LOCATION: database/migrations/2026_11_06_100002_create_registration_tracking_tables.php
//
// TASK 2 — Registration Team module. Purely additive: five new tables, no existing table is altered.
//
//   registration_visits          consented, pseudonymous record of a visitor opening the registration page (token stored as a SHA-256 hash;
//                                no IP address or user-agent is stored).
//   registration_applications    one row per applicant (users row with role investor) — the review workflow state and process timestamps.
//   registration_events          APPEND-ONLY audit trail of registration activity, status changes, reviewer actions and communications.
//   registration_communications  every message the Registration Team sends an applicant, with delivery status and a unique dedupe key.
//   registration_alerts          dedupe ledger for internal team notifications so the same event never alerts the team twice.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_visits', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->string('consent_notice_version', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('page_views')->default(1);
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('attempted_at')->nullable();     // first registration submission linked to this visit
            $table->timestamps();

            $table->index('opened_at');
            $table->index('user_id');
        });

        Schema::create('registration_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('registration_visits')->nullOnDelete();

            // pending | submitted | under_review | approved | rejected | additional_info_required
            $table->string('review_status', 32)->default('pending');
            $table->unsignedTinyInteger('stage_reached')->default(1);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('details_completed_at')->nullable();   // all four registration stages finished
            $table->timestamp('submitted_at')->nullable();           // handed to the team for review
            $table->timestamp('kyc_submitted_at')->nullable();       // latest KYC document submission
            $table->timestamp('review_started_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_reason')->nullable();
            $table->text('info_request')->nullable();                // what the applicant was last asked to provide

            // Follow-up controls (duplicate-send prevention and applicant opt-out).
            $table->unsignedSmallInteger('followup_count')->default(0);
            $table->timestamp('last_followup_at')->nullable();
            $table->timestamp('followup_opt_out_at')->nullable();

            $table->boolean('backfilled')->default(false);           // created from pre-existing data, not observed live
            $table->timestamps();

            $table->index('review_status');
            $table->index('started_at');
            $table->index('submitted_at');
            $table->index('decided_at');
            $table->index('assigned_to');
            $table->index(['review_status', 'last_activity_at']);
        });

        Schema::create('registration_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->nullable()->constrained('registration_applications')->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('registration_visits')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();   // null = the applicant / the system
            $table->string('actor_role', 32)->nullable();
            $table->string('event_type', 48);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['application_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('registration_communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('registration_applications')->cascadeOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 24);                // info_request | follow_up | status_notice
            $table->string('to_email');
            $table->string('subject');
            $table->text('body');
            $table->string('status', 16)->default('queued');   // queued | sent | failed
            $table->string('dedupe_key', 120)->unique();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'kind', 'created_at']);
            $table->index('status');
        });

        Schema::create('registration_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('dedupe_key', 120)->unique();
            $table->string('alert_type', 48);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['alert_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_alerts');
        Schema::dropIfExists('registration_communications');
        Schema::dropIfExists('registration_events');
        Schema::dropIfExists('registration_applications');
        Schema::dropIfExists('registration_visits');
    }
};
