<?php
// Document attachments for MarvFlow team requests. PURELY ADDITIVE: one new table. The existing single image
// attachment (attachment_url on team_requests / team_request_messages, stored on Cloudinary) is untouched, and no
// existing row or upload is modified, renamed or migrated.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_request_attachments', function (Blueprint $table) {
            $table->id();

            // The record this file belongs to. team_request_message_id is NULL for a file attached to the original
            // request, and set for a file attached to a reply.
            $table->foreignId('team_request_id')->constrained('team_requests')->onDelete('cascade');
            $table->foreignId('team_request_message_id')->nullable()->constrained('team_request_messages')->onDelete('cascade');
            $table->foreignId('uploader_id')->constrained('users')->onDelete('cascade');

            $table->string('original_name', 255);   // sanitised display name only — never used as a path
            $table->string('storage_path', 255);    // server-generated (uuid) path on the private disk
            $table->string('extension', 10);
            $table->string('mime_type', 120);       // canonical type from the whitelist, not the client's claim
            $table->unsignedBigInteger('size');     // bytes
            $table->string('sha256', 64);

            $table->timestamps();

            $table->index(['team_request_id']);
            $table->index(['team_request_message_id']);
        });
    }

    public function down(): void
    {
        // Drops only this table (metadata). Files already written to the private disk are NOT deleted by a rollback.
        Schema::dropIfExists('team_request_attachments');
    }
};
