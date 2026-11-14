<?php
// TASK 3 — departments, staff permissions, mailboxes. Additive only: no existing table is altered.
// down() drops only the tables created here, in FK-safe order.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mail_permissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('permission', 60);
            $t->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'permission']);
        });

        Schema::create('mail_departments', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 60)->unique();
            $t->string('name', 120);
            $t->string('environment', 12)->default('public');   // public | investor | both
            $t->string('description', 255)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('mail_department_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('department_id')->constrained('mail_departments')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('role', 10)->default('member');          // member | lead
            $t->timestamps();
            $t->unique(['department_id', 'user_id']);
        });

        Schema::create('mail_mailboxes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('department_id')->constrained('mail_departments')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete(); // one personal mailbox per staff member
            $t->string('address', 190)->unique();
            $t->string('display_name', 120);
            $t->string('provider', 20)->default('none');        // none | brevo
            $t->string('connection_status', 24)->default('not_connected'); // not_connected | connected | error
            $t->timestamp('sender_verified_at')->nullable();
            $t->foreignId('sender_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('last_checked_at')->nullable();
            $t->string('last_check_error', 255)->nullable();
            // A NAME of a secret held in the environment / secret manager. Never a password or key itself.
            $t->string('credential_ref', 64)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        $now = now();
        foreach ([
            ['general-enquiries', 'General Enquiries',         'public',   'Website contact form and public information requests'],
            ['registration',      'Registration & Onboarding', 'public',   'Correspondence with prospective investors'],
            ['investor-support',  'Investor Support',          'investor', 'Verified investor account and support correspondence'],
            ['financial',         'Financial Administration',  'investor', 'Financial and technical correspondence with investors'],
        ] as [$slug, $name, $env, $desc]) {
            DB::table('mail_departments')->insertOrIgnore([
                'slug' => $slug, 'name' => $name, 'environment' => $env, 'description' => $desc,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_mailboxes');
        Schema::dropIfExists('mail_department_members');
        Schema::dropIfExists('mail_departments');
        Schema::dropIfExists('mail_permissions');
    }
};
