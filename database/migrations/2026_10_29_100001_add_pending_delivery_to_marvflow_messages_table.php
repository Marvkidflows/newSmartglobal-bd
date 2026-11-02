<?php
// Pending delivery for SSI replies addressed to a MarvFlow user whose account is restricted.
// ONE nullable column; every existing row keeps NULL (= delivered / normal). No data is changed, no constraint is added.
//   pending_since NOT NULL → the reply is stored but NOT delivered: the owner cannot see it, is not alerted, and it is not counted
//                       for them. It is released (pending_since → NULL) the first time the owner uses messaging while `active`.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marvflow_messages', function (Blueprint $table) {
            $table->timestamp('pending_since')->nullable()->after('read_at');
            $table->index(['recipient_id', 'pending_since'], 'mfm_pending_idx');
        });
    }

    /**
     * SAFE ROLLBACK: refuses while any reply is still pending delivery. Dropping the column would make those replies look
     * delivered to a user whose account is restricted. Nothing is changed when it refuses — see APPLY.md.
     */
    public function down(): void
    {
        $pending = Schema::hasColumn('marvflow_messages', 'pending_since')
            ? DB::table('marvflow_messages')->whereNotNull('pending_since')->count()
            : 0;

        if ($pending > 0) {
            throw new \RuntimeException("Rollback refused: {$pending} reply row(s) are still pending delivery to restricted users. Back up the table and read APPLY.md (rollback section) first. Nothing was changed.");
        }

        Schema::table('marvflow_messages', function (Blueprint $table) {
            $table->dropIndex('mfm_pending_idx');
        });
        Schema::table('marvflow_messages', function (Blueprint $table) {
            $table->dropColumn('pending_since');
        });
    }
};
