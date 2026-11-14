<?php
// LOCATION: app/Services/TeamAttachmentService.php
//
// One place for everything about attachments on MarvFlow team requests / replies.
//
//   IMAGES   – unchanged. They are classified here only so each controller can keep sending them down its existing
//              Cloudinary path (team_requests.attachment_url / team_request_messages.attachment_url, 5 MB limit).
//   DOCUMENTS – PDF, DOCX, XLSX, TXT, MD. Verified by extension whitelist + sniffed content (never the client MIME),
//              size-limited (config team_documents.max_kb), stored on a PRIVATE disk under a server-generated uuid name,
//              recorded in team_request_attachments, and served only through authenticated, per-record-authorised routes.
//
// Rejected on purpose: every other extension (executables, scripts, SVG/HTML, archives…), legacy .doc/.xls and macro
// enabled .docm/.xlsm, OOXML containing macros/executables/unsafe paths/zip-bomb ratios, "PDF"/"TXT" files whose
// contents are not what the extension claims.

namespace App\Services;

use App\Models\TeamRequest;
use App\Models\TeamRequestAttachment;
use App\Models\TeamRequestMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class TeamAttachmentService
{
    /** Existing image limit (kilobytes) — unchanged from the original controllers. */
    public const IMAGE_MAX_KB = 5120;

    /**
     * Validate an uploaded attachment and say what it is.
     *
     * @return array{kind:'image'}|array{kind:'document',extension:string,mime:string}|null  null = no file was sent
     * @throws ValidationException (key "attachment") with a human-readable message
     */
    public function prepare(?UploadedFile $file): ?array
    {
        if (!$file) return null;

        if (!$file->isValid()) {
            $this->fail('The file could not be uploaded — it may be larger than the server allows. Please try a smaller file.');
        }

        if ($this->isImage($file)) {
            if ($file->getSize() > self::IMAGE_MAX_KB * 1024) {
                $this->fail('The attachment must not be greater than ' . self::IMAGE_MAX_KB . ' kilobytes.');
            }
            return ['kind' => 'image'];
        }

        return ['kind' => 'document'] + $this->inspectDocument($file);
    }

    /** Same test the original `image` validation rule applied (SVG is not an image under it). */
    public function isImage(UploadedFile $file): bool
    {
        return Validator::make(['attachment' => $file], ['attachment' => ['image']])->passes();
    }

    // ── Storage ──────────────────────────────────────────────────────────────

    /**
     * Store a document that prepare() already approved and record its metadata. Call inside the same DB transaction as
     * the request/message it belongs to. On failure nothing is left behind (no file without a row).
     *
     * @param array{extension:string,mime:string} $info
     */
    public function storeDocument(UploadedFile $file, array $info, TeamRequest $request, ?TeamRequestMessage $message, User $uploader): TeamRequestAttachment
    {
        $disk = $this->disk();
        $ext  = $info['extension'];
        $path = 'team-requests/' . (int) $request->id . '/' . now()->format('Y/m') . '/' . Str::uuid() . '.' . $ext;   // never the user's name

        $real   = $file->getRealPath();
        $stream = $real ? @fopen($real, 'rb') : false;
        if ($stream === false) $this->fail('The document could not be read. Please try again.');

        $ok = false;
        try {
            $ok = (bool) $disk->put($path, $stream);
        } catch (\Throwable $e) {
            Log::warning('Team document storage failed: ' . $e->getMessage());
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
        if (!$ok) $this->fail('The document could not be saved. Please try again.');

        try {
            return TeamRequestAttachment::create([
                'team_request_id'         => $request->id,
                'team_request_message_id' => $message?->id,
                'uploader_id'             => $uploader->id,
                'original_name'           => $this->safeName($file->getClientOriginalName(), $ext),
                'storage_path'            => $path,
                'extension'               => $ext,
                'mime_type'               => $info['mime'],
                'size'                    => (int) $file->getSize(),
                'sha256'                  => hash_file('sha256', $real),
            ]);
        } catch (\Throwable $e) {
            $disk->delete($path);          // no orphan file if the row could not be written
            throw $e;
        }
    }

    // ── Output ───────────────────────────────────────────────────────────────

    /** API shape. Never includes the storage path. */
    public function present(TeamRequestAttachment $a): array
    {
        return [
            'id'          => $a->id,
            'name'        => $a->original_name,
            'extension'   => $a->extension,
            'type'        => strtoupper($a->extension),
            'mime'        => $a->mime_type,
            'size'        => $a->size,
            'uploaded_at' => $a->created_at?->toIso8601String(),
            'uploaded_by' => $a->uploader?->name,
            'previewable' => $a->extension === 'pdf',
        ];
    }

    /**
     * Stream a stored document. The CALLER must already have authorised access to the parent request.
     * Always `attachment` (download) except an explicitly requested inline PDF preview; nosniff + locked-down CSP.
     */
    public function download(TeamRequestAttachment $a, bool $inline = false)
    {
        $path = (string) $a->storage_path;

        // Defence in depth: the path comes from our own database, but refuse anything not shaped like a path we generate.
        if (!preg_match('#^team-requests/\d+/\d{4}/\d{2}/[0-9a-f\-]{36}\.[a-z0-9]{1,5}$#', $path)) abort(404, 'File not found.');

        $disk = $this->disk();
        if (!$disk->exists($path)) abort(404, 'The file is no longer available.');

        return $disk->response($path, $a->original_name, [
            'Content-Type'            => $a->mime_type,
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control'           => 'private, no-store',
        ], ($inline && $a->extension === 'pdf') ? 'inline' : 'attachment');
    }

    // ── Document inspection ──────────────────────────────────────────────────

    /** @return array{extension:string,mime:string} */
    protected function inspectDocument(UploadedFile $file): array
    {
        $types = (array) config('team_documents.types');
        $ext   = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));

        if (!isset($types[$ext])) $this->fail($this->unsupportedMessage($ext));

        $size = (int) $file->getSize();
        if ($size <= 0) $this->fail('The file is empty.');

        $maxKb = (int) config('team_documents.max_kb');
        if ($size > $maxKb * 1024) {
            $this->fail('The file is too large. The maximum size for documents is ' . $this->formatKb($maxKb) . '.');
        }

        $path = $file->getRealPath();
        $mime = $this->detectMime($path);
        if (!in_array($mime, (array) config("team_documents.detected_mimes.$ext", []), true)) {
            $this->fail("This file's contents do not match a .{$ext} document, so it was rejected.");
        }

        match ($ext) {
            'pdf'          => $this->assertPdf($path),
            'docx', 'xlsx' => $this->assertOoxml($path, $ext),
            default        => $this->assertText($path),       // txt, md
        };

        return ['extension' => $ext, 'mime' => $types[$ext]];
    }

    protected function assertPdf(string $path): void
    {
        $head = (string) file_get_contents($path, false, null, 0, 1024);
        if (!str_contains($head, '%PDF-')) $this->fail('This file is not a valid PDF.');
    }

    protected function assertText(string $path): void
    {
        $content = (string) file_get_contents($path);
        if (str_contains($content, "\0")) $this->fail('This file contains binary data and is not a plain-text file.');
        if (!mb_check_encoding($content, 'UTF-8')) $this->fail('Text and Markdown files must be UTF-8 encoded.');
    }

    protected function assertOoxml(string $path, string $ext): void
    {
        if (!class_exists(ZipArchive::class)) {
            // Fail closed: an unverifiable Office file is never accepted.
            $this->fail('Office documents cannot be verified on this server right now, so this file was not accepted.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) $this->fail("This is not a valid .{$ext} document.");

        try {
            $limits = (array) config('team_documents.ooxml');
            if ($zip->numFiles < 1 || $zip->numFiles > (int) $limits['max_entries']) {
                $this->fail("This is not a valid .{$ext} document.");
            }

            $names = []; $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                $total += (int) ($stat['size'] ?? 0);

                if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                    $this->fail('This document contains unsafe file paths and was rejected.');
                }
                if (preg_match('/(^|\/)vbaProject\.bin$/i', $name) || preg_match('/\.(exe|dll|bat|cmd|com|scr|ps1|vbs|js|jar|msi|sh)$/i', $name)) {
                    $this->fail('Documents containing macros or executable content are not accepted.');
                }
                $names[strtolower($name)] = true;
            }

            if ($total > (int) $limits['max_uncompressed_bytes']) $this->fail('This document expands to an unsafe size and was rejected.');

            $core = $ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
            if (!isset($names['[content_types].xml'], $names[$core])) $this->fail("This is not a valid .{$ext} document.");

            $types = $zip->getFromName('[Content_Types].xml', 1048576);
            if ($types === false) $this->fail("This is not a valid .{$ext} document.");
            if (stripos($types, 'macroEnabled') !== false) $this->fail('Macro-enabled documents are not accepted.');
        } finally {
            $zip->close();
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    protected function detectMime(string $path): string
    {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $m = $f ? (string) finfo_file($f, $path) : '';
        if ($f) finfo_close($f);
        return $m;
    }

    /** Display-only name: no directories, no control characters, no markup, always ends in the VERIFIED extension. */
    protected function safeName(string $original, string $ext): string
    {
        $n = basename(str_replace('\\', '/', $original));
        $n = preg_replace('/[\x00-\x1F\x7F<>"|?*:]+/u', '', $n) ?? '';
        $base = trim(pathinfo($n, PATHINFO_FILENAME), " .");

        return ($base !== '' ? mb_substr($base, 0, 140) : 'document') . '.' . $ext;
    }

    protected function unsupportedMessage(string $ext): string
    {
        return match (true) {
            $ext === ''                          => 'The file has no extension, so its type cannot be determined.',
            in_array($ext, ['doc', 'xls'], true) => 'Legacy .doc / .xls files are not accepted because they can contain macros and cannot be verified. Please save the file as .docx or .xlsx.',
            in_array($ext, ['docm', 'xlsm'], true) => 'Macro-enabled documents are not accepted. Please save the file as .docx or .xlsx.',
            default => "Unsupported file type (.{$ext}). Allowed: images (JPG, PNG, GIF, WEBP, BMP) and documents (PDF, DOCX, XLSX, TXT, MD).",
        };
    }

    protected function formatKb(int $kb): string
    {
        return $kb >= 1024 ? rtrim(rtrim(number_format($kb / 1024, 1), '0'), '.') . ' MB' : $kb . ' KB';
    }

    protected function disk()
    {
        return Storage::disk((string) config('team_documents.disk'));
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['attachment' => [$message]]);
    }
}
