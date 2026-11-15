<?php
// LOCATION: tests/Feature/News/NewsCloudStorageTest.php   (NEW FILE)   WRITTEN, NOT EXECUTED in the build environment (no PHP runtime there).
//
// 1. NewsMediaService writes images THROUGH A STREAM (the Cloudinary adapter treats a raw string as a file path) and creates no database row
//    when the disk does not confirm the write.
// 2. `news:migrate-media`: copy -> verify (SHA-256) -> switch the row; never loses a file, never deletes without --delete-source, resumable.
// Real Cloudinary is never contacted: two in-memory/fake local disks stand in for the "old" and "new" disks.

namespace Tests\Feature\News;

use App\Models\News\NewsMedia;
use App\Services\News\NewsMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NewsCloudStorageTest extends TestCase
{
    use RefreshDatabase, NewsTestHelpers;

    private const PATH = 'news-media/2026/11/abc.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        // Storage::fake() registers only the driver instance, NOT the disk in config('filesystems.disks'). The commands (correctly) refuse a disk that is not
        // defined in config, so the two stand-in disks are defined first and then faked.
        foreach (['old_disk', 'new_disk'] as $d) {
            config(["filesystems.disks.$d" => ['driver' => 'local', 'root' => storage_path("framework/testing/disks/$d")]]);
        }
        Storage::fake('old_disk');
        Storage::fake('new_disk');
    }

    private function row(string $bytes, array $over = []): NewsMedia
    {
        return NewsMedia::create(array_merge([
            'kind' => 'image', 'disk' => 'old_disk', 'path' => self::PATH, 'mime' => 'image/jpeg',
            'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'status' => 'approved',
        ], $over));
    }

    // ---------------------------------------------------------------- service: writing

    public function test_images_are_written_from_a_real_temp_file_not_a_raw_string_or_in_memory_stream(): void
    {
        config(['news.disk' => 'stream_only_disk']);
        $disk = \Mockery::mock();
        // Must be a stream from a REAL file whose name carries the extension (Cloudinary rejects an in-memory stream and a raw string).
        $disk->shouldReceive('put')->once()->withArgs(function ($path, $contents) {
            if (!is_string($path) || !is_resource($contents)) return false;
            $uri = (string) (stream_get_meta_data($contents)['uri'] ?? '');
            return $uri !== '' && !str_starts_with($uri, 'php://') && str_ends_with($uri, '.png');
        })->andReturn(true);
        Storage::shouldReceive('disk')->with('stream_only_disk')->andReturn($disk);

        $media = app(NewsMediaService::class)->storeImage($this->png(), $this->staff(['news.review']), null, null);

        $this->assertSame('stream_only_disk', $media->disk);
        $this->assertSame(1, NewsMedia::count());
    }

    public function test_a_failed_write_creates_no_database_row_and_tells_the_user(): void
    {
        config(['news.disk' => 'broken_disk']);
        $disk = \Mockery::mock();
        $disk->shouldReceive('put')->andReturn(false);
        Storage::shouldReceive('disk')->with('broken_disk')->andReturn($disk);

        $threw = false;
        try {
            app(NewsMediaService::class)->storeImage($this->png(), $this->staff(['news.review']), null, null);
        } catch (ValidationException $e) {
            $threw = true;
            $this->assertStringContainsString('could not be saved', $e->errors()['file'][0]);
        }

        $this->assertTrue($threw, 'a failed write must be reported to the user');
        $this->assertSame(0, NewsMedia::count(), 'no row may exist for a file that was never stored');
    }

    public function test_an_exception_from_the_disk_is_also_reported_and_creates_no_row(): void
    {
        config(['news.disk' => 'throwing_disk']);
        $disk = \Mockery::mock();
        $disk->shouldReceive('put')->andThrow(new \RuntimeException('upstream refused'));
        Storage::shouldReceive('disk')->with('throwing_disk')->andReturn($disk);

        $threw = false;
        try {
            app(NewsMediaService::class)->storeImage($this->png(), $this->staff(['news.review']), null, null);
        } catch (ValidationException $e) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $this->assertSame(0, NewsMedia::count());
    }

    // ---------------------------------------------------------------- helper: DiskWriter

    public function test_disk_writer_stores_bytes_and_streams_identically_and_leaves_no_temp_file(): void
    {
        $before = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssi*') ?: [];

        $this->assertTrue(\App\Support\DiskWriter::put('new_disk', 'a/one.png', 'raw-bytes'));
        $h = fopen('php://temp', 'r+b'); fwrite($h, 'stream-bytes'); rewind($h);
        $this->assertTrue(\App\Support\DiskWriter::put('new_disk', 'a/two.mp4', $h));
        fclose($h);

        $this->assertSame('raw-bytes', Storage::disk('new_disk')->get('a/one.png'));
        $this->assertSame('stream-bytes', Storage::disk('new_disk')->get('a/two.mp4'));
        $this->assertSame($before, glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssi*') ?: [], 'no temporary file may be left behind');
    }

    public function test_disk_writer_reports_failure_and_still_removes_its_temp_file(): void
    {
        $before = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssi*') ?: [];
        $disk = \Mockery::mock();
        $disk->shouldReceive('put')->andReturn(false);
        Storage::shouldReceive('disk')->with('refusing_disk')->andReturn($disk);

        $this->assertFalse(\App\Support\DiskWriter::put('refusing_disk', 'a/x.png', 'bytes'));
        $this->assertSame($before, glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssi*') ?: []);
    }

    // ---------------------------------------------------------------- command: news:migrate-media

    public function test_it_copies_verifies_and_switches_the_row_and_keeps_the_original_by_default(): void
    {
        $bytes = 'image-bytes-' . Str::random(40);
        Storage::disk('old_disk')->put(self::PATH, $bytes);
        $m = $this->row($bytes);

        $this->artisan('news:migrate-media', ['--to' => 'new_disk'])->assertSuccessful();

        $this->assertSame('new_disk', $m->fresh()->disk);
        $this->assertSame($bytes, Storage::disk('new_disk')->get(self::PATH));
        Storage::disk('old_disk')->assertExists(self::PATH);
    }

    public function test_delete_source_removes_the_original_only_after_a_verified_copy(): void
    {
        $bytes = 'image-bytes-' . Str::random(40);
        Storage::disk('old_disk')->put(self::PATH, $bytes);
        $m = $this->row($bytes);

        $this->artisan('news:migrate-media', ['--to' => 'new_disk', '--delete-source' => true])->assertSuccessful();

        $this->assertSame('new_disk', $m->fresh()->disk);
        Storage::disk('new_disk')->assertExists(self::PATH);
        Storage::disk('old_disk')->assertMissing(self::PATH);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $bytes = 'image-bytes-' . Str::random(40);
        Storage::disk('old_disk')->put(self::PATH, $bytes);
        $m = $this->row($bytes);

        $this->artisan('news:migrate-media', ['--to' => 'new_disk', '--dry-run' => true, '--delete-source' => true])->assertSuccessful();

        $this->assertSame('old_disk', $m->fresh()->disk);
        Storage::disk('new_disk')->assertMissing(self::PATH);
        Storage::disk('old_disk')->assertExists(self::PATH);
    }

    public function test_a_copy_that_does_not_match_the_recorded_hash_is_not_switched_and_the_original_is_never_deleted(): void
    {
        $bytes = 'image-bytes-' . Str::random(40);
        Storage::disk('old_disk')->put(self::PATH, $bytes);
        $m = $this->row($bytes, ['sha256' => hash('sha256', 'something else entirely')]);

        $this->artisan('news:migrate-media', ['--to' => 'new_disk', '--delete-source' => true])->assertFailed();

        $this->assertSame('old_disk', $m->fresh()->disk, 'an unverified row must stay on the old disk');
        Storage::disk('old_disk')->assertExists(self::PATH);
    }

    public function test_a_file_already_missing_from_its_old_disk_is_reported_and_its_row_left_alone(): void
    {
        $m = $this->row('never stored');   // row exists, file does not

        $this->artisan('news:migrate-media', ['--to' => 'new_disk'])
            ->expectsOutputToContain('MISSING')
            ->assertSuccessful();

        $this->assertSame('old_disk', $m->fresh()->disk);
        Storage::disk('new_disk')->assertMissing(self::PATH);
    }

    public function test_it_is_idempotent_and_skips_rows_already_on_the_target_and_embeds(): void
    {
        $bytes = 'image-bytes-' . Str::random(40);
        Storage::disk('old_disk')->put(self::PATH, $bytes);
        $this->row($bytes);
        $embed = NewsMedia::create(['kind' => 'video_embed', 'embed_provider' => 'youtube', 'embed_id' => 'abc123', 'status' => 'approved']);

        $this->artisan('news:migrate-media', ['--to' => 'new_disk'])->assertSuccessful();
        $this->artisan('news:migrate-media', ['--to' => 'new_disk'])->expectsOutputToContain('0 file(s) are not on')->assertSuccessful();

        $this->assertSame(1, NewsMedia::where('disk', 'new_disk')->count());
        $this->assertNull($embed->fresh()->disk);
    }

    public function test_it_changes_only_the_disk_column(): void
    {
        $bytes = 'image-bytes-' . Str::random(40);
        Storage::disk('old_disk')->put(self::PATH, $bytes);
        $before = $this->row($bytes, ['alt_text' => 'Alt', 'caption' => 'Cap']);
        $snapshot = collect($before->fresh()->getAttributes())->except(['disk', 'created_at', 'updated_at'])->all();

        $this->artisan('news:migrate-media', ['--to' => 'new_disk'])->assertSuccessful();

        $after = collect($before->fresh()->getAttributes())->except(['disk', 'created_at', 'updated_at'])->all();
        $this->assertEquals($snapshot, $after);
    }

    public function test_an_undefined_target_disk_is_refused(): void
    {
        $this->artisan('news:migrate-media', ['--to' => 'no_such_disk'])->assertFailed();
    }

    // ---------------------------------------------------------------- command: files:verify-storage

    public function test_verify_storage_passes_on_a_working_disk_and_leaves_no_test_file_behind(): void
    {
        config(['news.disk' => 'new_disk', 'team_documents.disk' => 'new_disk']);

        $this->artisan('files:verify-storage')->expectsOutputToContain('All checks passed.')->assertSuccessful();

        $this->assertSame([], array_filter(Storage::disk('new_disk')->allFiles(), fn ($f) => str_starts_with($f, '_verify/')));
    }

    public function test_verify_storage_fails_for_an_undefined_disk(): void
    {
        config(['news.disk' => 'no_such_disk', 'team_documents.disk' => 'new_disk']);

        $this->artisan('files:verify-storage')->assertFailed();
    }

    public function test_verify_storage_flags_rows_whose_file_is_missing(): void
    {
        config(['news.disk' => 'new_disk', 'team_documents.disk' => 'new_disk']);
        $this->row('never stored', ['disk' => 'new_disk']);

        $this->artisan('files:verify-storage', ['--check-rows' => true])->expectsOutputToContain('MISSING')->assertFailed();
    }
}
