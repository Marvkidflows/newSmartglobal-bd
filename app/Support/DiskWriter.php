<?php
// LOCATION: app/Support/DiskWriter.php   (NEW FILE)
//
// Writes bytes (a string) or the contents of a stream to a Laravel disk in a way that works on EVERY disk, including Cloudinary.
//
// WHY: the Cloudinary disk cannot take a raw byte string (it treats a string as a file PATH), and a stream created in memory (php://temp) reaches
// Cloudinary's upload request WITHOUT a filename, which Cloudinary rejects ("Invalid request parameters"; confirmed with a diagnostic: uploading from a real
// file path works, uploading from an in-memory stream does not). So the data is first written to a real temporary file whose name carries the right
// extension, uploaded from that, and the temporary file is always removed afterwards.

namespace App\Support;

use Illuminate\Support\Facades\Storage;

final class DiskWriter
{
    /**
     * @param  string           $diskName  name from config/filesystems.php
     * @param  string           $path      destination path on the disk (its extension is reused for the temp file)
     * @param  string|resource  $source    raw bytes, or a readable stream
     * @return bool  true only if the disk reported success
     */
    public static function put(string $diskName, string $path, $source): bool
    {
        $base = tempnam(sys_get_temp_dir(), 'ssi');
        if ($base === false) return false;

        $ext = preg_replace('/[^A-Za-z0-9]/', '', pathinfo($path, PATHINFO_EXTENSION));
        $tmp = $ext !== '' ? $base . '.' . $ext : $base;
        if ($tmp !== $base && !@rename($base, $tmp)) { @unlink($base); return false; }

        try {
            $out = fopen($tmp, 'wb');
            if ($out === false) return false;
            try {
                if (is_resource($source)) stream_copy_to_stream($source, $out);
                else fwrite($out, (string) $source);
            } finally {
                fclose($out);
            }

            $in = fopen($tmp, 'rb');
            if ($in === false) return false;
            try {
                return (bool) Storage::disk($diskName)->put($path, $in);
            } finally {
                if (is_resource($in)) fclose($in);
            }
        } finally {
            @unlink($tmp);
        }
    }
}
