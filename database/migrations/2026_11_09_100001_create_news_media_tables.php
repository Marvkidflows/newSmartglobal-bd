<?php
// TASK 4 — News & Media: categories, media, articles, staff permissions, audit. Additive only; the existing `announcements`
// (News & Information Centre) table is NOT touched. down() drops only these tables, FK-safe.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('news_categories', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 60)->unique();
            $t->string('name', 100);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('news_media', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 12);                                  // image | video_file | video_embed
            $t->string('disk', 20)->nullable();
            $t->string('path', 255)->nullable();
            $t->string('original_name', 190)->nullable();
            $t->string('mime', 80)->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->string('sha256', 64)->nullable();
            $t->string('embed_provider', 12)->nullable();
            $t->string('embed_id', 40)->nullable();
            $t->string('alt_text', 190)->nullable();
            $t->string('caption', 255)->nullable();
            $t->string('status', 10)->default('pending');           // pending | approved | rejected
            $t->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('moderated_at')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('status');
        });

        Schema::create('news_articles', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 190)->unique();
            $t->string('title', 190);
            $t->string('summary', 500)->nullable();
            $t->longText('body');                                    // sanitised HTML
            $t->string('content_type', 20)->default('article');     // article|announcement|market_news|educational|video|promotion|investor_notice
            $t->foreignId('category_id')->nullable()->constrained('news_categories')->nullOnDelete();
            $t->string('visibility', 10)->default('public');        // public | members | investor
            $t->string('status', 12)->default('draft');             // draft|submitted|approved|rejected|scheduled|published|unpublished
            $t->foreignId('cover_media_id')->nullable()->constrained('news_media')->nullOnDelete();
            $t->foreignId('video_media_id')->nullable()->constrained('news_media')->nullOnDelete();
            $t->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('author_name', 120);
            $t->string('department', 40)->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_note', 1000)->nullable();
            $t->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->boolean('is_featured')->default(false);
            $t->softDeletes();
            $t->timestamps();
            $t->index(['status', 'visibility', 'published_at']);
            $t->index('author_id');
        });

        Schema::create('news_permissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('permission', 40);
            $t->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'permission']);
        });

        Schema::create('news_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 50);
            $t->string('subject_type', 30)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('metadata')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['subject_type', 'subject_id']);
        });

        $now = now();
        foreach ([['company-updates', 'Company Updates'], ['market-news', 'Market News'], ['education', 'Educational Content'],
                  ['announcements', 'Announcements'], ['investor-notices', 'Investor Notices'], ['promotions', 'Promotions']] as $i => [$slug, $name]) {
            DB::table('news_categories')->insertOrIgnore(['slug' => $slug, 'name' => $name, 'sort_order' => $i, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('news_audit_logs');
        Schema::dropIfExists('news_permissions');
        Schema::dropIfExists('news_articles');
        Schema::dropIfExists('news_media');
        Schema::dropIfExists('news_categories');
    }
};
