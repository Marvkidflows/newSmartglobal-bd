<?php
// LOCATION: app/Console/Commands/NewsMigrateMedia.php   (NEW FILE)
//
//   php artisan news:migrate-media --to=cloudinary --dry-run          # report only: what would move, what is already missing
//   php artisan news:migrate-media --to=cloudinary                    # copy + verify + switch each row; originals are KEPT
//   php artisan news:migrate-media --to=cloudinary --delete-source    # ...and delete the original only after the copy is verified
//
// Moves existing News media files (images + uploaded videos; embeds have no file) from whichever disk each row currently names to --to.
// Safety properties:
//   * Per row: read from the old disk -> write to the new disk at the SAME path -> read it back and compare SHA-256 with the hash stored on the row
//     (size only if a row has no hash) -> ONLY THEN update the row's `disk`. A row that fails verification is left exactly as it was.
//   * The original is never deleted unless --delete-source is given, and then only after the verification above passed.
//   * Resumable and idempotent: rows already on the target disk are skipped, so it can be re-run after an interruption.
//   * A file already missing from its old disk is reported (it cannot be recovered by this command) and the row is left untouched.
//   * Nothing but the `disk` column of a verified row is ever changed; no article, status, audience or other data is touched.

namespace App\Console\Commands;

use App\Models\News\NewsMedia;
use App\Support\DiskWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class NewsMigrateMedia extends Command
{
    protected $signature = 'news:migrate-media {--to=cloudinary : target disk name} {--dry-run : report only, change nothing} {--delete-source : delete the original after the copy is verified} {--limit=0 : stop after this many rows (0 = all)}';
    protected $description = 'Copy existing News media to another disk (default: Cloudinary), verify each file by SHA-256, then switch the row.';

    public function handle(): int
    {
        $to = (string) $this->option('to');
        $dry = (bool) $this->option('dry-run');
        $deleteSource = (bool) $this->option('delete-source');
        $limit = max(0, (int) $this->option('limit'));

        if (config("filesystems.disks.$to") === null) { $this->error("Disk '$to' is not defined in config/filesystems.php."); return self::FAILURE; }
        if (config("filesystems.disks.$to.driver") === 'cloudinary' && !config('filesystems.disks.cloudinary.url')) { $this->error('CLOUDINARY_URL is not set.'); return self::FAILURE; }
        if ($deleteSource && $dry) $this->warn('--delete-source is ignored in --dry-run.');

        $query = NewsMedia::query()->whereNotNull('path')->whereNotNull('disk')->where('disk', '!=', $to)->where('kind', '!=', 'video_embed')->orderBy('id');
        $total = (clone $query)->count();
        $this->line(($dry ? '[dry-run] ' : '') . "$total file(s) are not on '$to'.");
        if ($total === 0) return self::SUCCESS;

        $moved = 0; $missing = 0; $failed = 0; $seen = 0;
        // Collect ids first, then load each row on its own: rows are updated inside the loop, which must not happen while a DB cursor is open.
        foreach ((clone $query)->pluck('id') as $id) {
            if ($limit > 0 && $seen >= $limit) break;
            $m = NewsMedia::find($id);
            if (!$m) continue;
            $seen++;
            $from = (string) $m->disk;
            $path = (string) $m->path;
            try {
                $src = Storage::disk($from);
                if (!$src->exists($path)) { $missing++; $this->warn("  MISSING   #{$m->id}  $from:$path  (not on its old disk; cannot be migrated)"); continue; }
                if ($dry) { $moved++; $this->line("  would move #{$m->id}  $from:$path  (" . (int) $m->size . ' bytes)'); continue; }

                $in = $src->readStream($path);
                if (!is_resource($in)) throw new \RuntimeException('could not open the original for reading');
                try { $ok = DiskWriter::put($to, $path, $in); } finally { if (is_resource($in)) fclose($in); }
                if (!$ok) throw new \RuntimeException('the target disk did not accept the file');

                if (!$this->verify($to, $path, (string) $m->sha256, (int) $m->size)) throw new \RuntimeException('verification failed: the copy does not match the original');

                $m->forceFill(['disk' => $to])->save();
                if ($deleteSource) { try { $src->delete($path); } catch (\Throwable $e) { $this->warn("    copied and switched, but could not delete the original: " . Str::limit($e->getMessage(), 100)); } }
                $moved++;
                $this->line("  moved     #{$m->id}  $from:$path -> $to");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  FAILED    #{$m->id}  $from:$path  " . Str::limit($e->getMessage(), 160));
            }
        }

        $this->newLine();
        $this->line(($dry ? 'Would move' : 'Moved') . ": $moved   Missing on old disk: $missing   Failed: $failed");
        if ($missing > 0) $this->warn("$missing file(s) no longer exist on their old disk (for example a redeploy wiped a local disk). They must be re-uploaded.");
        if (!$dry && $moved > 0 && !$deleteSource) $this->line('Originals were kept. Re-run with --delete-source later, once you have checked the site, to free the old disk.');
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Reads the copy back from the target and compares it with the recorded SHA-256 (or the recorded size when there is no hash). */
    private function verify(string $to, string $path, string $sha256, int $size): bool
    {
        $disk = Storage::disk($to);
        if (!$disk->exists($path)) return false;
        $r = $disk->readStream($path);
        if (!is_resource($r)) return false;
        $ctx = hash_init('sha256'); $bytes = 0;
        while (!feof($r)) { $chunk = fread($r, 1048576); if ($chunk === false) break; $bytes += strlen($chunk); hash_update($ctx, $chunk); }
        fclose($r);
        if ($sha256 !== '') return hash_equals($sha256, hash_final($ctx));
        return $size > 0 && $bytes === $size;
    }
}
