<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // Raw MODIFY is MySQL-only syntax; SQLite (used by the test suite)
        // has dynamic column typing and doesn't need/support this at all,
        // so it's skipped there rather than erroring on a fresh migration.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users MODIFY country VARCHAR(100) NULL');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users MODIFY country VARCHAR(3) NULL');
        }
    }
};
