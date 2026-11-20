<?php
// ADDITIVE ONLY (19 Nov 2026). Open/closed state for public contact-support requests that are already stored in `messages`.
// No existing table or row is altered. Absence of a row means "open".
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_request_states', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->unique()->constrained('messages')->cascadeOnDelete();
            $t->string('status', 10)->default('open');   // open | closed
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->string('updated_by_name', 120)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_request_states');
    }
};
