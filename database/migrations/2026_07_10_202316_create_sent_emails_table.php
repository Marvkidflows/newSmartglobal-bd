<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sent_emails', function (Blueprint $table) {
            $table->id();

            // Grouping key for bulk sends — null for single Compose emails
            $table->string('batch_id')->nullable()->index();

            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('investor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('recipient_name')->nullable();
            $table->string('recipient_email');

            $table->string('subject');
            $table->longText('body_html');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();

            $table->enum('status', ['sent', 'failed', 'queued'])->default('queued');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();

            // Read tracking on the investor's side (Email History page)
            $table->boolean('read_by_investor')->default(false);
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['investor_id', 'read_by_investor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_emails');
    }
};