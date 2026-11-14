<?php
// TASK 3 — templates, campaigns, the unified message log and the administrative audit trail.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mail_templates', function (Blueprint $t) {
            $t->id();
            $t->string('environment', 10);              // public | investor — a template never crosses environments
            $t->string('name', 120);
            $t->string('subject', 190);
            $t->longText('body_html');
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('environment');
        });

        Schema::create('mail_campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('environment', 10);
            $t->string('name', 150);
            $t->string('subject', 190);
            $t->longText('body_html');
            $t->foreignId('template_id')->nullable()->constrained('mail_templates')->nullOnDelete();
            $t->string('kind', 16);                     // newsletter | announcement | essential
            $t->string('purpose', 40)->nullable();      // consent purpose (null for essential)
            $t->string('audience_type', 40);
            $t->json('audience_filters')->nullable();
            $t->string('status', 16)->default('draft'); // draft|scheduled|queued|sending|sent|partial|failed|cancelled
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->unsignedInteger('recipient_count')->default(0);
            $t->unsignedInteger('sent_count')->default(0);
            $t->unsignedInteger('failed_count')->default(0);
            $t->unsignedInteger('skipped_count')->default(0);
            $t->string('client_token', 64)->nullable()->unique(); // makes a double-clicked "create" idempotent
            $t->foreignId('department_id')->nullable()->constrained('mail_departments')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['environment', 'status']);
        });

        Schema::create('mail_messages', function (Blueprint $t) {
            $t->id();
            $t->string('environment', 10);
            $t->string('direction', 10)->default('outbound');   // outbound | inbound
            $t->string('kind', 16)->default('transactional');   // newsletter|announcement|essential|transactional|enquiry|support
            $t->string('purpose', 40)->nullable();
            $t->foreignId('campaign_id')->nullable()->constrained('mail_campaigns')->nullOnDelete();
            $t->foreignId('department_id')->nullable()->constrained('mail_departments')->nullOnDelete();
            $t->foreignId('mailbox_id')->nullable()->constrained('mail_mailboxes')->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();      // recipient / sender account, if any
            $t->foreignId('contact_id')->nullable()->constrained('mail_contacts')->nullOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('from_address', 190)->nullable();
            $t->string('recipient_email', 190);
            $t->string('recipient_name', 120)->nullable();
            $t->string('subject', 255);
            $t->longText('body_html')->nullable();
            $t->string('status', 16)->default('queued');        // queued|sending|sent|delivered|failed|bounced|skipped|received
            $t->string('skip_reason', 40)->nullable();
            $t->string('error_code', 40)->nullable();
            $t->string('error_message', 255)->nullable();       // sanitised; never contains credentials
            $t->string('provider_message_id', 190)->nullable()->index();
            $t->string('idempotency_key', 64)->nullable()->unique();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('queued_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->timestamps();
            $t->index(['environment', 'status']);
            $t->index(['department_id', 'created_at']);
            $t->index('recipient_email');
            $t->index('campaign_id');
        });

        Schema::create('mail_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 60);
            $t->string('environment', 10)->nullable();
            $t->string('subject_type', 40)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('metadata')->nullable();                    // sanitised: no bodies, passwords or tokens
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['action', 'created_at']);
            $t->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_audit_logs');
        Schema::dropIfExists('mail_messages');
        Schema::dropIfExists('mail_campaigns');
        Schema::dropIfExists('mail_templates');
    }
};
