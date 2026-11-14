<?php
// TASK 3 — public contacts, current subscription state and the append-only consent ledger.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mail_contacts', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();           // used in signed unsubscribe / confirm links instead of the e-mail address
            $t->string('email', 190)->unique();         // stored lower-case
            $t->string('name', 120)->nullable();
            $t->string('source', 30)->default('contact_form');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 20)->default('active'); // active | bounced | suppressed
            $t->timestamps();
        });

        // CURRENT state per (email, purpose). The ledger below is the history.
        Schema::create('mail_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->string('email', 190);
            $t->foreignId('contact_id')->nullable()->constrained('mail_contacts')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('purpose', 40);
            $t->string('status', 16);                   // pending | granted | withdrawn
            $t->timestamp('changed_at');
            $t->timestamps();
            $t->unique(['email', 'purpose']);
            $t->index(['purpose', 'status']);
        });

        Schema::create('mail_consents', function (Blueprint $t) {
            $t->id();
            $t->string('email', 190)->index();
            $t->foreignId('contact_id')->nullable()->constrained('mail_contacts')->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('purpose', 40);
            $t->string('action', 16);                   // requested | granted | withdrawn
            $t->string('source', 30);
            $t->string('policy_version', 20)->nullable();
            $t->string('ip_hash', 64)->nullable();      // salted hash, never the raw address
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_consents');
        Schema::dropIfExists('mail_subscriptions');
        Schema::dropIfExists('mail_contacts');
    }
};
