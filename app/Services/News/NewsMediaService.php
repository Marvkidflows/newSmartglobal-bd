<?php
// TASK 4 — secure media handling: validation, safe storage, secure embeds and streaming.
//
// Images : jpg/png/webp only (no SVG/GIF), real MIME sniffed from content, getimagesize() must succeed, bounded dimensions and size,
//          embedded script/PHP markers rejected, re-encoded through GD when available (strips EXIF + polyglot payloads).
// Video  : mp4/webm only, real MIME sniffed, container magic bytes checked, size-capped. Stored on a PRIVATE disk under a random name.
// Embeds : YouTube / Vimeo only. Only provider + video id are stored; the iframe URL is rebuilt server-side.
// Nothing is ever served from a guessable public path: files are streamed by NewsMediaController after an access check.
namespace App\Services\News;

use App\Models\News\NewsMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NewsMediaService
{
    protected function fail(string $msg): never
    {
        throw ValidationException::withMessages(['file' => $msg]);
    }

    protected function autoApproved(User $u): bool
    {
        return NewsPermissions::can($u, 'news.review') || NewsPermissions::can($u, 'news.publish');
    }

    public function storeImage(UploadedFile $file, User $user, ?string $alt, ?string $caption): NewsMedia
    {
        if (!$file->isValid()) $this->fail('The upload failed. Please try again.');
        $maxKb = (int) config('news.max_image_kb');
        if ($file->getSize() > $maxKb * 1024) $this->fail("Images may not exceed {$maxKb} KB.");

        $mimes = (array) config('news.image_mimes');
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();                                  // sniffed from content (finfo), not the client header
        if (!isset($mimes[$mime]) || !in_array($ext, array_unique([$mimes[$mime], $mime === 'image/jpeg' ? 'jpeg' : $mimes[$mime]]), true)) {
            $this->fail('Only JPG, PNG or WebP images are allowed.');
        }
        $path = $file->getRealPath();
        $info = @getimagesize($path);
        if (!$info || ($info['mime'] ?? '') !== $mime) $this->fail('The file is not a valid image.');
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || max($w, $h) > (int) config('news.max_image_pixels')) $this->fail('Image dimensions are not allowed.');

        $bytes = (string) file_get_contents($path);
        $this->rejectActiveContent($bytes);
        $bytes = $this->reencode($bytes, $mime);                               // strips metadata / polyglot data where GD is available

        $name = Str::random(40) . '.' . $mimes[$mime];
        $rel = trim(config('news.directory'), '/') . '/' . date('Y/m') . '/' . $name;
        Storage::disk(config('news.disk'))->put($rel, $bytes);

        return NewsMedia::create([
            'kind' => 'image', 'disk' => config('news.disk'), 'path' => $rel, 'original_name' => Str::limit(basename($file->getClientOriginalName()), 180, ''),
            'mime' => $mime, 'size' => strlen($bytes), 'width' => $w, 'height' => $h, 'sha256' => hash('sha256', $bytes),
            'alt_text' => $alt ? Str::limit(strip_tags($alt), 190, '') : null, 'caption' => $caption ? Str::limit(strip_tags($caption), 255, '') : null,
            'status' => $this->autoApproved($user) ? 'approved' : 'pending', 'uploaded_by' => $user->id,
            'moderated_by' => $this->autoApproved($user) ? $user->id : null, 'moderated_at' => $this->autoApproved($user) ? now() : null,
        ]);
    }

    public function storeVideo(UploadedFile $file, User $user, ?string $alt, ?string $caption): NewsMedia
    {
        if (!$file->isValid()) $this->fail('The upload failed. Please try again.');
        $maxKb = (int) config('news.max_video_kb');
        if ($file->getSize() > $maxKb * 1024) $this->fail('Videos may not exceed ' . intdiv($maxKb, 1024) . ' MB.');

        $mimes = (array) config('news.video_mimes');
        $ext = strtolower($file->getClientOriginalExtension());
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 65536);

        // The container signature decides the type (not the client header, not the extension). libmagic names MP4/WebM variants differently on different
        // platforms (video/mp4, video/x-m4v, application/mp4, application/octet-stream, video/x-matroska ...), so the sniffed MIME is only required to be
        // one of those or a clearly non-video type is refused; the extension must then match the signature.
        $mime = substr($head, 4, 4) === 'ftyp' ? 'video/mp4' : (str_starts_with($head, "\x1A\x45\xDF\xA3") ? 'video/webm' : null);
        $sniffed = (string) $file->getMimeType();
        $tolerated = ['video/mp4', 'video/webm', 'video/x-m4v', 'video/quicktime', 'application/mp4', 'application/octet-stream', 'video/x-matroska'];
        if ($mime === null || !isset($mimes[$mime]) || $ext !== $mimes[$mime]) $this->fail('Only MP4 or WebM videos are allowed.');
        if (!in_array($sniffed, $tolerated, true)) $this->fail('The file is not a valid video.');
        $this->rejectActiveContent($head);

        $name = Str::random(40) . '.' . $mimes[$mime];
        $rel = trim(config('news.directory'), '/') . '/' . date('Y/m') . '/' . $name;
        Storage::disk(config('news.disk'))->putFileAs(dirname($rel), $file, basename($rel));

        return NewsMedia::create([
            'kind' => 'video_file', 'disk' => config('news.disk'), 'path' => $rel, 'original_name' => Str::limit(basename($file->getClientOriginalName()), 180, ''),
            'mime' => $mime, 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()),
            'alt_text' => $alt ? Str::limit(strip_tags($alt), 190, '') : null, 'caption' => $caption ? Str::limit(strip_tags($caption), 255, '') : null,
            'status' => $this->autoApproved($user) ? 'approved' : 'pending', 'uploaded_by' => $user->id,
            'moderated_by' => $this->autoApproved($user) ? $user->id : null, 'moderated_at' => $this->autoApproved($user) ? now() : null,
        ]);
    }

    public function storeEmbed(string $url, User $user, ?string $caption): NewsMedia
    {
        $parsed = $this->parseEmbed($url);
        if (!$parsed) throw ValidationException::withMessages(['url' => 'Only YouTube and Vimeo links are supported.']);
        [$provider, $id] = $parsed;
        return NewsMedia::create([
            'kind' => 'video_embed', 'embed_provider' => $provider, 'embed_id' => $id, 'caption' => $caption ? Str::limit(strip_tags($caption), 255, '') : null,
            'status' => $this->autoApproved($user) ? 'approved' : 'pending', 'uploaded_by' => $user->id,
            'moderated_by' => $this->autoApproved($user) ? $user->id : null, 'moderated_at' => $this->autoApproved($user) ? now() : null,
        ]);
    }

    /** @return array{0:string,1:string}|null [provider, id] */
    public function parseEmbed(string $url): ?array
    {
        $url = trim($url);
        if (strlen($url) > 300 || preg_match('/[\s\x00-\x1F]/', $url)) return null;
        $p = parse_url($url);
        if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return null;
        $host = strtolower($p['host']); $path = $p['path'] ?? ''; parse_str($p['query'] ?? '', $q);
        $yt = fn ($id) => is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? ['youtube', $id] : null;

        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'www.youtube-nocookie.com', 'youtube-nocookie.com'], true)) {
            if ($path === '/watch') return $yt($q['v'] ?? null);
            if (preg_match('#^/(embed|shorts)/([^/]+)$#', $path, $m)) return $yt($m[2]);
            return null;
        }
        if ($host === 'youtu.be' && preg_match('#^/([^/]+)$#', $path, $m)) return $yt($m[1]);
        if (in_array($host, ['vimeo.com', 'www.vimeo.com'], true) && preg_match('#^/(\d{5,12})$#', $path, $m)) return ['vimeo', $m[1]];
        if ($host === 'player.vimeo.com' && preg_match('#^/video/(\d{5,12})$#', $path, $m)) return ['vimeo', $m[1]];
        return null;
    }

    public function embedUrl(NewsMedia $m): ?string
    {
        if ($m->kind !== 'video_embed') return null;
        return $m->embed_provider === 'youtube'
            ? 'https://www.youtube-nocookie.com/embed/' . $m->embed_id
            : 'https://player.vimeo.com/video/' . $m->embed_id;
    }

    public function delete(NewsMedia $m): void
    {
        if ($m->path && $m->disk) Storage::disk($m->disk)->delete($m->path);
        $m->delete();
    }

    protected function rejectActiveContent(string $bytes): void
    {
        if (preg_match('/<\?php|<\?=|<script\b|<svg\b|<html\b/i', substr($bytes, 0, 1048576))) $this->fail('The file contains disallowed content.');
    }

    protected function reencode(string $bytes, string $mime): string
    {
        if (!function_exists('imagecreatefromstring') || !in_array($mime, ['image/jpeg', 'image/png'], true)) return $bytes;
        $im = @imagecreatefromstring($bytes);
        if (!$im) $this->fail('The file is not a valid image.');
        ob_start();
        if ($mime === 'image/png') { imagealphablending($im, false); imagesavealpha($im, true); imagepng($im); }
        else imagejpeg($im, null, 88);
        $out = (string) ob_get_clean();
        imagedestroy($im);
        return $out !== '' ? $out : $bytes;
    }
}
