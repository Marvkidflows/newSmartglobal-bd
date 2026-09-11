<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('tasks')) {
            Schema::create('tasks', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->text('description');
                $table->decimal('reward', 10, 2);
                $table->date('active_date');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('task_completions')) {
            Schema::create('task_completions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('task_id')->constrained()->onDelete('cascade');
                $table->timestamp('completed_at');
                $table->timestamps();

                $table->unique(['user_id', 'task_id']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('task_completions');
        Schema::dropIfExists('tasks');
    }
};
