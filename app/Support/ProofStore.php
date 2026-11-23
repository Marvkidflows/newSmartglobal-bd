<?php
// LOCATION: app/Support/ProofStore.php   (NEW FILE)
//
// Stores an investor's payment-proof image. DEPOSIT_PROOF_DISK (config uploads.deposit_proof_disk) chooses the disk:
//   public (default) -> exactly the old behaviour: store('deposit-screenshots','public'); returns a relative path.
//   cloudinary       -> uploaded through DiskWriter; returns the absolute https URL, which survives deploys.
// If the chosen remote disk fails, the file falls back to the local public disk so a deposit is never blocked by storage.

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ProofStore
{
    public static function save(UploadedFile $file, string $dir = 'deposit-screenshots'): string
    {
        $disk = (string) config('uploads.deposit_proof_disk', 'public');

        if ($disk !== 'public') {
            $ext  = strtolower((string) ($file->guessExtension() ?: 'jpg'));
            $path = $dir . '/' . Str::uuid() . '.' . $ext;
            try {
                $in = @fopen((string) $file->getRealPath(), 'rb');
                if ($in !== false) {
                    try {
                        if (DiskWriter::put($disk, $path, $in)) {
                            $url = (string) Storage::disk($disk)->url($path);
                            if ($url !== '') return $url;
                        }
                    } finally { if (is_resource($in)) fclose($in); }
                }
            } catch (\Throwable $e) {
                Log::warning('Payment-proof upload to "' . $disk . '" failed, using local disk: ' . $e->getMessage());
            }
        }

        return $file->store($dir, 'public');
    }
}
