<?php
// LOCATION: database/migrations/2026_08_22_100004_add_news_centre_fields_to_announcements_table.php
//
// Extends the EXISTING announcements table into the News & Information
// Centre, per approved plan (extend, don't duplicate). Additive only —
// no existing column is dropped or renamed, so the current
// AdminAnnouncementController / InvestorAnnouncementController / bell
// dropdown / popup keep working against `is_active` unchanged.
//
// New `status` (draft|scheduled|published|unpublished) becomes the
// source of truth for visibility going forward; `is_active` is kept in
// sync by the Announcement model's mutator/boot logic for backward
// compatibility with any code still reading it directly.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            if (!Schema::hasColumn('announcements', 'slug')) {
                $table->string('slug')->nullable()->after('id');
            }
            if (!Schema::hasColumn('announcements', 'summary')) {
                $table->string('summary', 500)->nullable()->after('title');
            }
            if (!Schema::hasColumn('announcements', 'image_url')) {
                $table->string('image_url')->nullable()->after('content');
            }
            if (!Schema::hasColumn('announcements', 'category')) {
                $table->string('category')->default('general')->after('type');
            }
            if (!Schema::hasColumn('announcements', 'status')) {
                $table->string('status')->default('published')->after('is_active');
            }
            if (!Schema::hasColumn('announcements', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('is_popup');
            }
            if (!Schema::hasColumn('announcements', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('announcements', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable()->after('published_at');
            }
            if (!Schema::hasColumn('announcements', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')
                      ->constrained('users')->nullOnDelete();
            }
        });

        // ── BACKFILL existing rows so nothing already published disappears ──
        DB::table('announcements')->orderBy('id')->chunk(100, function ($rows) {
            foreach ($rows as $row) {
                $status = $row->is_active ? 'published' : 'unpublished';
                $slug = $row->slug ?: Str::slug($row->title) . '-' . $row->id;

                DB::table('announcements')->where('id', $row->id)->update([
                    'status'       => $status,
                    'slug'         => $slug,
                    'published_at' => $row->is_active ? $row->created_at : null,
                ]);
            }
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn([
                'slug', 'summary', 'image_url', 'category',
                'status', 'is_featured', 'published_at', 'scheduled_at',
            ]);
        });
    }
};
