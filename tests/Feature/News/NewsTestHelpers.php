<?php
// TASK 4 — shared helpers for the news feature tests. WRITTEN, NOT EXECUTED in the build environment (no PHP runtime there).
namespace Tests\Feature\News;

use App\Models\News\NewsArticle;
use App\Models\News\NewsCategory;
use App\Models\News\NewsMedia;
use App\Models\News\NewsPermission;
use App\Models\User;
use Illuminate\Http\UploadedFile;

trait NewsTestHelpers
{
    protected function admin(): User { return User::factory()->create(['role' => 'admin']); }

    protected function staff(array $perms = [], string $role = 'financial'): User
    {
        $u = User::factory()->create(['role' => $role]);
        foreach ($perms as $p) NewsPermission::create(['user_id' => $u->id, 'permission' => $p]);
        return $u;
    }

    protected function verifiedInvestor(): User
    {
        return User::factory()->create(['role' => 'investor', 'status' => 'active', 'kyc_verified' => true, 'email_verified_at' => now()]);
    }

    protected function applicant(): User
    {
        return User::factory()->create(['role' => 'investor', 'status' => 'active', 'kyc_verified' => false]);
    }

    protected function article(array $o = []): NewsArticle
    {
        $a = new NewsArticle(['title' => $o['title'] ?? 'Article ' . uniqid(), 'body' => '<p>Body</p>', 'content_type' => 'article',
            'visibility' => $o['visibility'] ?? 'public', 'category_id' => NewsCategory::first()->id]);
        $a->forceFill(['status' => $o['status'] ?? 'published', 'author_name' => 'Tester', 'author_id' => $o['author_id'] ?? null,
            'published_at' => array_key_exists('published_at', $o) ? $o['published_at'] : now()->subMinute(),
            'scheduled_at' => $o['scheduled_at'] ?? null, 'cover_media_id' => $o['cover_media_id'] ?? null])->save();
        return $a;
    }

    protected function png(string $name = 'a.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }

    protected function mp4(string $name = 'v.mp4'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\0", 2048));
    }

    protected function approvedImage(User $u): NewsMedia
    {
        return NewsMedia::create(['kind' => 'image', 'disk' => 'local', 'path' => 'news-media/x.png', 'mime' => 'image/png', 'status' => 'approved', 'uploaded_by' => $u->id]);
    }
}
