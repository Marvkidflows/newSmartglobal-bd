<?php
// TASK 4 — upload security, secure embeds, media serving. WRITTEN, NOT EXECUTED in the build environment.
namespace Tests\Feature\News;

use App\Models\News\NewsMedia;
use App\Services\News\NewsMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class NewsMediaTest extends TestCase
{
    use RefreshDatabase, NewsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['news.disk' => 'local']);
    }

    public function test_valid_image_is_stored_privately_under_a_random_name_and_pending_for_contributors(): void
    {
        $c = $this->staff(['news.contribute']);
        $res = $this->actingAs($c, 'sanctum')->post('/api/news-admin/media/image', ['file' => $this->png('Holiday Photo.PNG'), 'alt' => '<b>Alt</b>'], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('pending', $res->json('media.status'));
        $m = NewsMedia::first();
        $this->assertNotSame('Holiday Photo.PNG', basename($m->path));
        $this->assertMatchesRegularExpression('#^news-media/\d{4}/\d{2}/[A-Za-z0-9]{40}\.png$#', $m->path);
        Storage::disk('local')->assertExists($m->path);
        $this->assertSame('Alt', $m->alt_text);
        $this->assertStringNotContainsString($m->path, json_encode($res->json()));
    }

    public function test_media_uploaded_by_a_reviewer_is_auto_approved(): void
    {
        $r = $this->staff(['news.review']);
        $this->actingAs($r, 'sanctum')->post('/api/news-admin/media/image', ['file' => $this->png()], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('media.status', 'approved');
    }

    public function test_dangerous_or_disguised_images_are_rejected(): void
    {
        $c = $this->staff(['news.contribute']);
        $post = fn (UploadedFile $f) => $this->actingAs($c, 'sanctum')->post('/api/news-admin/media/image', ['file' => $f], ['Accept' => 'application/json']);
        $post(UploadedFile::fake()->createWithContent('x.png', '<?php system($_GET["c"]); ?>'))->assertStatus(422);                       // PHP renamed .png
        $post(UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertStatus(422);
        $post(UploadedFile::fake()->createWithContent('x.php', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')))->assertStatus(422);   // real PNG, .php extension
        $post(UploadedFile::fake()->createWithContent('x.gif', "GIF89a\x01\x00\x01\x00\x00\x00\x00;"))->assertStatus(422);
        $post(UploadedFile::fake()->createWithContent('x.png', "\x89PNG\r\n\x1a\n<script>alert(1)</script>"))->assertStatus(422);
        $this->assertSame(0, NewsMedia::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_oversized_images_are_rejected(): void
    {
        config(['news.max_image_kb' => 0]);
        $this->actingAs($this->staff(['news.contribute']), 'sanctum')->post('/api/news-admin/media/image', ['file' => $this->png()], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_video_upload_checks_container_type_and_size(): void
    {
        $c = $this->staff(['news.contribute']);
        $this->actingAs($c, 'sanctum')->post('/api/news-admin/media/video', ['file' => $this->mp4()], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('media.kind', 'video_file');
        $this->actingAs($c, 'sanctum')->post('/api/news-admin/media/video', ['file' => UploadedFile::fake()->createWithContent('v.mp4', 'not a video at all')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($c, 'sanctum')->post('/api/news-admin/media/video', ['file' => UploadedFile::fake()->createWithContent('v.exe', "MZ\x90\x00")], ['Accept' => 'application/json'])->assertStatus(422);
        config(['news.max_video_kb' => 0]);
        $this->actingAs($c, 'sanctum')->post('/api/news-admin/media/video', ['file' => $this->mp4()], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_only_youtube_and_vimeo_embeds_are_accepted_and_the_iframe_url_is_rebuilt(): void
    {
        $svc = app(NewsMediaService::class);
        $this->assertSame(['youtube', 'dQw4w9WgXcQ'], $svc->parseEmbed('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=5'));
        $this->assertSame(['youtube', 'dQw4w9WgXcQ'], $svc->parseEmbed('https://youtu.be/dQw4w9WgXcQ'));
        $this->assertSame(['vimeo', '123456789'], $svc->parseEmbed('https://vimeo.com/123456789'));
        foreach (['http://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtube.com.evil.com/watch?v=dQw4w9WgXcQ', 'https://evil.com/?https://youtu.be/dQw4w9WgXcQ',
                  'javascript:alert(1)', 'https://www.youtube.com/watch?v=short', 'https://user:pw@www.youtube.com/watch?v=dQw4w9WgXcQ',
                  "https://youtu.be/dQw4w9WgXcQ\"><script>", 'https://www.youtube.com/watch?v=dQw4w9WgXcQ extra', 'https://vimeo.com/abc'] as $bad) {
            $this->assertNull($svc->parseEmbed($bad), $bad);
        }
        $c = $this->staff(['news.contribute']);
        $res = $this->actingAs($c, 'sanctum')->postJson('/api/news-admin/media/embed', ['url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertCreated();
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $res->json('media.embed_url'));
        $this->actingAs($c, 'sanctum')->postJson('/api/news-admin/media/embed', ['url' => 'https://evil.com/x'])->assertStatus(422);
    }

    public function test_public_media_route_serves_only_media_of_live_public_articles(): void
    {
        $u = $this->admin();
        Storage::disk('local')->put('news-media/pub.png', 'PNGDATA');
        Storage::disk('local')->put('news-media/inv.png', 'PNGDATA');
        $pubM = NewsMedia::create(['kind' => 'image', 'disk' => 'local', 'path' => 'news-media/pub.png', 'mime' => 'image/png', 'status' => 'approved', 'uploaded_by' => $u->id]);
        $invM = NewsMedia::create(['kind' => 'image', 'disk' => 'local', 'path' => 'news-media/inv.png', 'mime' => 'image/png', 'status' => 'approved', 'uploaded_by' => $u->id]);
        $orphan = NewsMedia::create(['kind' => 'image', 'disk' => 'local', 'path' => 'news-media/pub.png', 'mime' => 'image/png', 'status' => 'approved', 'uploaded_by' => $u->id]);
        $this->article(['cover_media_id' => $pubM->id]);
        $this->article(['visibility' => 'investor', 'cover_media_id' => $invM->id]);

        $this->get('/api/public/news/media/' . $pubM->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/api/public/news/media/' . $invM->id)->assertNotFound();      // investor-only article's media is not reachable anonymously
        $this->get('/api/public/news/media/' . $orphan->id)->assertNotFound();    // attached to nothing
    }

    public function test_signed_media_urls_work_until_tampered_and_are_issued_only_for_authorised_feeds(): void
    {
        $u = $this->admin(); $inv = $this->verifiedInvestor();
        Storage::disk('local')->put('news-media/inv.png', 'PNGDATA');
        $m = NewsMedia::create(['kind' => 'image', 'disk' => 'local', 'path' => 'news-media/inv.png', 'mime' => 'image/png', 'status' => 'approved', 'uploaded_by' => $u->id]);
        $a = $this->article(['visibility' => 'investor', 'cover_media_id' => $m->id]);

        $url = $this->actingAs($inv, 'sanctum')->getJson('/api/investor/news/' . $a->slug)->assertOk()->json('article.cover.url');
        $this->assertStringContainsString('signature=', $url);
        $this->get($url)->assertOk();
        $this->get(preg_replace('#/media/\d+#', '/media/' . ($m->id + 1), $url))->assertForbidden();                     // signature bound to the id
        $this->get('/api/news/media/' . $m->id)->assertForbidden();                                                         // unsigned

        $applicantView = $this->actingAs($this->applicant(), 'sanctum')->getJson('/api/investor/news/' . $a->slug);
        $applicantView->assertNotFound();                                                                                    // no URL is ever issued to an ineligible viewer
    }

    public function test_media_moderation_and_deletion_rules(): void
    {
        $contrib = $this->staff(['news.contribute']); $rev = $this->staff(['news.review']); $mgr = $this->staff(['news.manage']);
        $id = $this->actingAs($contrib, 'sanctum')->post('/api/news-admin/media/image', ['file' => $this->png()], ['Accept' => 'application/json'])->json('media.id');
        $this->actingAs($contrib, 'sanctum')->postJson("/api/news-admin/media/$id/approve")->assertForbidden();
        $this->actingAs($rev, 'sanctum')->postJson("/api/news-admin/media/$id/approve")->assertOk()->assertJsonPath('media.status', 'approved');

        $this->article(['cover_media_id' => $id]);
        $this->actingAs($contrib, 'sanctum')->deleteJson("/api/news-admin/media/$id")->assertStatus(409);                    // in use by a live article
        $this->actingAs($rev, 'sanctum')->postJson("/api/news-admin/media/$id/reject")->assertOk();
        $this->assertSame('unpublished', \App\Models\News\NewsArticle::first()->status);                                    // rejected media pulls the article
        $this->actingAs($mgr, 'sanctum')->deleteJson("/api/news-admin/media/$id")->assertOk();
        $this->assertNull(NewsMedia::find($id));
    }

    public function test_upload_endpoints_require_a_news_permission(): void
    {
        $this->post('/api/news-admin/media/image', ['file' => $this->png()], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->actingAs($this->verifiedInvestor(), 'sanctum')->post('/api/news-admin/media/image', ['file' => $this->png()], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($this->staff(), 'sanctum')->post('/api/news-admin/media/image', ['file' => $this->png()], ['Accept' => 'application/json'])->assertForbidden();
    }
}
