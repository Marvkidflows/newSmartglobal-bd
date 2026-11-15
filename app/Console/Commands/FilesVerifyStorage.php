<?php
// LOCATION: app/Console/Commands/FilesVerifyStorage.php   (NEW FILE)
//
//   php artisan files:verify-storage                 # round-trip test of the News media disk and the Team documents disk
//   php artisan files:verify-storage --check-rows    # also confirm every News media row's file really exists on the disk it points at
//   php artisan files:verify-storage --check-rows --limit=200
//
// WHY: "the file was saved" must be proven, not assumed. For each configured disk this writes a tiny throw-away PNG through the SAME calls the
// application uses (temp-file write -> exists -> size -> response(), which is how NewsMediaController / TeamAttachmentService serve a file), compares the bytes
// that come back with the bytes that went in (SHA-256), deletes the test file and confirms it is gone. It writes only under a "_verify/" folder, never
// touches a real upload, and exits non-zero if anything fails so it can be used as a deploy check.
//
// NOTE: on the Cloudinary disk each check is a Cloudinary Admin API call, so --check-rows on a large library is rate-limited by your Cloudinary plan; use --limit.

namespace App\Console\Commands;

use App\Models\News\NewsMedia;
use App\Support\DiskWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FilesVerifyStorage extends Command
{
    protected $signature = 'files:verify-storage {--check-rows : also check that each stored News media file exists on its disk} {--limit=100 : max News media rows to check with --check-rows}';
    protected $description = 'Prove that the News media and Team documents disks really store, serve and delete files (round-trip test).';

    // 1x1 transparent PNG
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function handle(): int
    {
        $failed = false;

        $this->line('Cloudinary credentials : ' . (config('filesystems.disks.cloudinary.url') ? 'CLOUDINARY_URL is set' : 'CLOUDINARY_URL is NOT set'));
        $this->newLine();

        $targets = [
            'News media'     => (string) config('news.disk'),
            'Team documents' => (string) config('team_documents.disk'),
        ];

        $rows = [];
        foreach ($targets as $label => $diskName) {
            [$ok, $detail] = $this->roundTrip($diskName);
            $failed = $failed || !$ok;
            $rows[] = [$label, $diskName ?: '(not set)', config("filesystems.disks.$diskName.driver") ?? '(undefined)', $ok ? 'PASS' : 'FAIL', $detail];
        }
        $this->table(['Module', 'Disk', 'Driver', 'Result', 'Detail'], $rows);

        $this->reportRowsByDisk();

        if ($this->option('check-rows') && !$this->checkRows()) $failed = true;

        $this->newLine();
        $failed ? $this->error('FAILED: at least one check did not pass. Do not rely on this disk until it is fixed.') : $this->info('All checks passed.');
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{0: bool, 1: string} */
    private function roundTrip(string $diskName): array
    {
        if ($diskName === '' || config("filesystems.disks.$diskName") === null) return [false, 'disk is not defined in config/filesystems.php'];
        if (config("filesystems.disks.$diskName.driver") === 'cloudinary' && !config('filesystems.disks.cloudinary.url')) return [false, 'CLOUDINARY_URL is not set'];

        $bytes = base64_decode(self::PNG);
        $hash = hash('sha256', $bytes);
        $path = '_verify/' . Str::random(24) . '.png';
        $disk = Storage::disk($diskName);
        $stage = 'write';

        try {
            $written = DiskWriter::put($diskName, $path, $bytes);
            if (!$written) return [false, 'write returned false (nothing stored)'];

            $stage = 'exists';
            if (!$disk->exists($path)) return [false, 'file not found right after writing'];

            $stage = 'size';
            $size = $disk->size($path);
            if ((int) $size !== strlen($bytes)) return [false, "size mismatch (wrote " . strlen($bytes) . ", disk reports $size)"];

            $stage = 'serve (response)';
            ob_start();
            $disk->response($path, null, ['Content-Type' => 'image/png'])->sendContent();
            $served = (string) ob_get_clean();
            if (hash('sha256', $served) !== $hash) return [false, 'bytes served back differ from the bytes written (' . strlen($served) . ' bytes returned)'];

            $stage = 'delete';
            $disk->delete($path);
            if ($disk->exists($path)) return [false, 'file still exists after delete'];

            return [true, 'write, exists, size, serve (byte-identical) and delete all OK'];
        } catch (\Throwable $e) {
            try { $disk->delete($path); } catch (\Throwable) { /* best-effort cleanup of the test file */ }
            return [false, "failed at '$stage': " . Str::limit($e->getMessage(), 160)];
        }
    }

    private function reportRowsByDisk(): void
    {
        $this->newLine();
        $counts = NewsMedia::query()->whereNotNull('path')->selectRaw('disk, count(*) as n')->groupBy('disk')->pluck('n', 'disk');
        if ($counts->isEmpty()) { $this->line('News media files recorded: none yet.'); return; }
        $this->line('News media files recorded, by disk:');
        foreach ($counts as $disk => $n) $this->line("  - $disk : $n");
        $stray = $counts->except(['cloudinary'])->sum();
        if ($stray > 0) $this->warn("  $stray file(s) are NOT on Cloudinary. Move them with: php artisan news:migrate-media --to=cloudinary --dry-run");
    }

    private function checkRows(): bool
    {
        $limit = max(1, (int) $this->option('limit'));
        $this->newLine();
        $this->line("Checking up to $limit News media row(s) (newest first)...");
        $missing = 0; $checked = 0;
        NewsMedia::query()->whereNotNull('path')->whereNotNull('disk')->orderByDesc('id')->limit($limit)->get()->each(function (NewsMedia $m) use (&$missing, &$checked) {
            $checked++;
            try { $present = Storage::disk($m->disk)->exists($m->path); } catch (\Throwable $e) { $present = false; }
            if (!$present) { $missing++; $this->warn("  MISSING  #{$m->id}  disk={$m->disk}  path={$m->path}"); }
        });
        $this->line("Checked $checked row(s): " . ($missing === 0 ? 'every file is present.' : "$missing file(s) MISSING."));
        return $missing === 0;
    }
}
