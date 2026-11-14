<?php
// LOCATION: app/Services/RichMessageBody.php
//
// ONE place that turns "what the client sent" into "what is stored" for the EXISTING message centers
// (Admin↔Investor, Financial↔Investor incl. broadcasts, Admin↔Financial staff channel).
//
// Storage contract (backward compatible):
//   body       = PLAIN TEXT (always). Previews, search, notifications, audit rows and any old client keep reading this column.
//   body_html  = sanitised HTML, or NULL for plain/legacy messages.
// A client may send EITHER the legacy plain `body` OR the new `body_html`. If `body_html` is present it wins: it is sanitised
// (message profile), `body` is DERIVED from the sanitised HTML, and the existing limit of 5,000 characters is applied to that plain text.

namespace App\Services;

class RichMessageBody
{
    public const PLAIN_MAX = 5000;     // characters of plain text — the limit the channels already had
    public const HTML_MAX  = 40000;    // characters of submitted HTML

    public function __construct(protected RichTextSanitizer $sanitizer) {}

    /**
     * @param  array $input  the validated request data (keys `body`, `body_html`)
     * @return array{body: string, body_html: ?string}|array{error: string}
     */
    public function fromInput(array $input): array
    {
        $html = $input['body_html'] ?? null;

        if (is_string($html) && trim($html) !== '') {
            if (mb_strlen($html) > self::HTML_MAX) return ['error' => 'The message is too long. Please shorten it.'];

            $safe = $this->sanitizer->sanitize($html);
            $text = $this->plainText($this->sanitizer->toText($safe));
            if ($text === '')                         return ['error' => 'The message is empty.'];
            if (mb_strlen($text) > self::PLAIN_MAX)   return ['error' => 'Messages are limited to ' . number_format(self::PLAIN_MAX) . ' characters.'];

            return ['body' => $text, 'body_html' => $safe];
        }

        $plain = trim((string) ($input['body'] ?? ''));
        if ($plain === '')                          return ['error' => 'The message is empty.'];
        if (mb_strlen($plain) > self::PLAIN_MAX)    return ['error' => 'Messages are limited to ' . number_format(self::PLAIN_MAX) . ' characters.'];

        return ['body' => $plain, 'body_html' => null];
    }

    /** Like fromInput(), but a problem becomes an ordinary ValidationException (JSON → 422, web form → redirect back with errors). */
    public function resolve(array $input): array
    {
        $r = $this->fromInput($input);
        if (isset($r['error'])) throw \Illuminate\Validation\ValidationException::withMessages(['body' => [$r['error']]]);
        return $r;
    }

    /**
     * The derived plain text must behave like text a person typed: the editor can emit &nbsp;, which html_entity_decode() turns into a
     * NON-BREAKING space (U+00A0) — invisible to a plain "hello world" LIKE search and to legacy clients. Normalise it to a normal space,
     * and drop zero-width characters. (Done here, not in RichTextSanitizer::toText, so MarvFlow's own text derivation is untouched.)
     */
    protected function plainText(string $text): string
    {
        $text = preg_replace('/\x{00A0}/u', ' ', $text) ?? $text;
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    /** What to send to a client for display: sanitised AGAIN on the way out (null for legacy rows). */
    public function forDisplay(?string $storedHtml): ?string
    {
        if ($storedHtml === null || trim($storedHtml) === '') return null;
        $safe = $this->sanitizer->sanitize($storedHtml);
        return $safe === '' ? null : $safe;
    }

    /** 422 body in the same shape the existing endpoints use. */
    public static function error(string $message, string $field = 'body'): \Illuminate\Http\JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
