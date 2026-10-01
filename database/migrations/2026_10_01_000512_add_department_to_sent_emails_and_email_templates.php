<?php
// LOCATION: database/migrations/2026_09_30_100001_add_department_to_sent_emails_and_email_templates.php
//
// Lets the EXISTING Email Center (sent_emails / email_templates) be reused by
// the Financial Team without a second email system.
//
//   sent_emails.department   'admin' (default — every existing row) | 'financial'
//   sent_emails.sender_label display identity stored at send time, so history
//                            never changes if a staff member is renamed/removed
//   email_templates.department  null = shared/general (existing rows),
//                               'financial' = owned by the Financial Team

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sent_emails', function (Blueprint $table) {
            if (!Schema::hasColumn('sent_emails', 'department')) {
                $table->string('department', 30)->default('admin')->after('admin_id')->index();
            }
            if (!Schema::hasColumn('sent_emails', 'sender_label')) {
                $table->string('sender_label')->nullable()->after('department');
            }
        });

        Schema::table('email_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('email_templates', 'department')) {
                $table->string('department', 30)->nullable()->after('category')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sent_emails', function (Blueprint $table) {
            foreach (['sender_label', 'department'] as $col) {
                if (Schema::hasColumn('sent_emails', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('email_templates', function (Blueprint $table) {
            if (Schema::hasColumn('email_templates', 'department')) {
                $table->dropColumn('department');
            }
        });
    }
};
