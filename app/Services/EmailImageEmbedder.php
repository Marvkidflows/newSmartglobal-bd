<?php
// LOCATION: app/Services/EmailImageEmbedder.php
//
// Images in the Email Center are stored in the email body as `data:image/…;base64,…` (or https URLs). Gmail, Outlook and most
// clients BLOCK data: images, so a recipient would see a broken picture. When the mail is actually being SENT, every data: image is
// attached as an INLINE (cid:) part instead — the image then shows in every client. The stored body (and what the investor sees in the
// dashboard inbox) keeps the original data: URI, so the editor preview, the sent email and the investor inbox show the same image.
// https images are left as they are (clients may still ask the recipient before loading remote images; alt text is always kept).
//
// Operates only on HTML that has ALREADY been through RichTextSanitizer (email profile), so the <img src="…"> shape is predictable.
// Any problem falls back to the original HTML — a sending failure must never be caused by this step.

namespace App\Services;

class EmailImageEmbedder
{
    public const MAX_IMAGES = 20;

    /** @param object $message  Illuminate\Mail\Message (anything exposing embedData($data, $name, $contentType)) */
    public function embed(string $safeHtml, $message): string
    {
        if ($safeHtml === '' || !is_object($message) || !method_exists($message, 'embedData') || stripos($safeHtml, 'data:image/') === false) {
            return $safeHtml;
        }

        $count = 0;
        try {
            $out = preg_replace_callback(
                '#(<img\b[^>]*?\bsrc=")data:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/]++={0,2})(")#i',
                function (array $m) use ($message, &$count) {
                    if ($count >= self::MAX_IMAGES) return $m[0];                      // beyond the cap the data: URI is left as is
                    $binary = base64_decode($m[3], true);
                    if ($binary === false || $binary === '') return $m[0];
                    $mime = 'image/' . strtolower($m[2]);
                    $ext  = strtolower($m[2]) === 'jpeg' ? 'jpg' : strtolower($m[2]);
                    $count++;
                    return $m[1] . $message->embedData($binary, "inline-image-{$count}.{$ext}", $mime) . $m[4];   // → "cid:inline-image-N.ext"
                },
                $safeHtml
            );
        } catch (\Throwable $e) {
            return $safeHtml;
        }

        return is_string($out) ? $out : $safeHtml;                                      // preg error → original HTML
    }
}
