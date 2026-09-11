<?php
// LOCATION: database/migrations/2024_01_01_000006_create_messages_table.php
// Run: php artisan migrate

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            // Who sent it — admin OR investor
            $table->foreignId('sender_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            // Who receives it — admin OR investor
            $table->foreignId('receiver_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            // The investor this conversation belongs to
            // (always set — makes it easy to group conversations per investor)
            $table->foreignId('investor_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            $table->string('subject')->nullable();
            $table->text('body');                        // the message content

            // Who initiated this thread
            $table->enum('initiated_by', ['admin', 'investor'])->default('investor');

            // Read receipts
            $table->boolean('read_by_admin')->default(false);
            $table->boolean('read_by_investor')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
