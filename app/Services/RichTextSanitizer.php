<?php
// LOCATION: app/Services/RichTextSanitizer.php
//
// TWO PROFILES (same engine, same fail-closed rules):
//   'message' (default) — chat-style messages: MarvFlow, Admin/Investor/Financial/staff messages. Behaviour for every input that
//                         was valid before is UNCHANGED (MarvFlow stored content stays identical); only MORE dangerous things are dropped.
//   'email'             — the Email Center and the investor Emails inbox: a superset that also keeps headings, hr, simple tables and
//                         images (https or raster data: URIs only), plus a few more validated style properties.
//
// Allow-list HTML sanitiser for rich-text messages (stored-XSS defence). The only things that survive are the formatting
// the MarvFlow composer can produce: paragraphs, line breaks, bold, italic, underline, strike, lists, indentation
// (class ql-indent-1..8 only), SAFE hyperlinks (http, https, mailto only — rel/target are forced), and inline styles
// limited to a validated set (font-size, font-family from a fixed list, color, background-color, text-align,
// line-height, margin-top/bottom).
// Everything else is removed: scripts/styles/iframes/objects/svg/forms, images, javascript:/data: links, every other
// attribute, event handlers, comments, processing instructions. Unknown-but-harmless wrappers are unwrapped (their
// text is kept). The result is also safe to sanitise again (idempotent).

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

class RichTextSanitizer
{
    /** tags kept as-is (attributes stripped except filtered style) */
    protected const ALLOWED = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'span', 'div', 'blockquote', 'a'];

    /** font-family values the composer offers (exact, case-insensitive match; nothing else is ever emitted) */
    protected const FONTS = ['arial' => 'Arial', 'georgia' => 'Georgia', 'verdana' => 'Verdana', 'tahoma' => 'Tahoma', 'courier' => 'Courier'];

    /** tags removed together with everything inside them */
    protected const DROP = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'svg', 'math', 'form', 'input',
        'textarea', 'select', 'button', 'option', 'link', 'meta', 'base', 'template', 'noscript', 'head', 'title', 'audio', 'video',
        'source', 'canvas', 'img', 'picture', 'xml'];

    protected const MAX_DEPTH = 40;

    public const PROFILE_MESSAGE = 'message';
    public const PROFILE_EMAIL   = 'email';

    /** extra tags the email profile keeps (img is also removed from DROP for that profile) */
    protected const EMAIL_EXTRA = ['h1', 'h2', 'h3', 'h4', 'hr', 'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td'];

    /** raw-text / legacy elements whose content a browser may re-parse differently from libxml: dropped WITH their content */
    protected const DROP_EXTRA = ['xmp', 'plaintext', 'noframes', 'noembed', 'listing', 'isindex', 'keygen', 'bgsound', 'marquee', 'portal', 'dialog'];

    /** Hard bounds so a hostile payload cannot burn CPU/memory. Writers are limited much lower by their controllers. */
    protected const MAX_INPUT_BYTES = ['message' => 400000, 'email' => 8388608];
    protected const MAX_NODES       = ['message' => 20000,  'email' => 200000];
    protected const MAX_DATA_IMAGE  = 1048576;   // characters of one data:image URI

    /** extra attributes per tag (email profile only); every value is validated, anything else is removed */
    protected const EMAIL_ATTRS = [
        'img'   => ['alt', 'width', 'height'],
        'table' => ['width', 'cellpadding', 'cellspacing', 'border', 'align'],
        'td'    => ['colspan', 'rowspan', 'width', 'align', 'valign'],
        'th'    => ['colspan', 'rowspan', 'width', 'align', 'valign'],
        'tr'    => ['align', 'valign'],
        'p'     => ['align'], 'div' => ['align'], 'h1' => ['align'], 'h2' => ['align'], 'h3' => ['align'], 'h4' => ['align'],
    ];

    protected string $profile = self::PROFILE_MESSAGE;
    protected array  $allowed = self::ALLOWED;
    protected array  $drop    = self::DROP;
    protected int    $nodes   = 0;
    protected int    $maxNodes = 20000;

    public function sanitize(string $html, string $profile = self::PROFILE_MESSAGE): string
    {
        $html = trim($html);
        if ($html === '') return '';

        $this->setProfile($profile);
        if (strlen($html) > self::MAX_INPUT_BYTES[$this->profile]) return '';          // fail closed: never pass an oversize payload through

        $dom = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><body><div data-root="1">' . $html . '</div></body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($loaded === false) return '';                                                // fail closed on a parse failure

        $root = null;
        foreach ($dom->getElementsByTagName('div') as $div) {
            if ($div->hasAttribute('data-root')) { $root = $div; break; }
        }
        if (!$root) return '';

        try {
            $this->nodes = 0;
            $this->clean($root, 0);
        } catch (\OverflowException $e) {
            return '';                                                                   // too many elements: fail closed
        }

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }
        return trim($out);
    }

    public function sanitizeEmail(string $html): string
    {
        return $this->sanitize($html, self::PROFILE_EMAIL);
    }

    protected function setProfile(string $profile): void
    {
        $this->profile  = $profile === self::PROFILE_EMAIL ? self::PROFILE_EMAIL : self::PROFILE_MESSAGE;
        $this->maxNodes = self::MAX_NODES[$this->profile];
        if ($this->profile === self::PROFILE_EMAIL) {
            $this->allowed = array_merge(self::ALLOWED, self::EMAIL_EXTRA);
            $this->drop    = array_values(array_diff(array_merge(self::DROP, self::DROP_EXTRA), ['img']));
        } else {
            $this->allowed = self::ALLOWED;
            $this->drop    = array_merge(self::DROP, self::DROP_EXTRA);
        }
    }

    /** Plain-text rendering (previews, notifications). */
    public function toText(string $safeHtml): string
    {
        $t = preg_replace('#</(p|div|li|blockquote)>|<br\s*/?>#i', "\n", $safeHtml);
        $t = html_entity_decode(strip_tags((string) $t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t]+/u", ' ', $t) ?? $t;
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
        return trim($t);
    }

    /** Subject: plain text only — tags removed, control characters removed, whitespace collapsed. */
    public function plain(string $text, int $max = 150): string
    {
        // strip_tags() removes the tags but KEEPS what is inside <script>/<style>/…, so drop those elements together with their content first.
        $t = preg_replace('#<(script|style|template|noscript|iframe|object|embed|svg|math|xmp|noembed|noframes|textarea|title)\b[^>]*>.*?(?:</\1\s*>|$)#is', ' ', $text) ?? '';
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $t) ?? '';
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
        return mb_substr($t, 0, $max);
    }

    protected function clean(DOMNode $node, int $depth): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE) continue;

            if (!$child instanceof DOMElement) {              // comments, CDATA, processing instructions …
                $node->removeChild($child);
                continue;
            }

            $tag = strtolower($child->tagName);

            if (++$this->nodes > $this->maxNodes) throw new \OverflowException('too many elements');

            if ($depth >= self::MAX_DEPTH || in_array($tag, $this->drop, true)) {
                $node->removeChild($child);
                continue;
            }

            if (!in_array($tag, $this->allowed, true)) {     // unknown wrapper (a, font, h1, table …): keep its text, drop the tag
                $this->clean($child, $depth + 1);
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }

            if ($tag === 'a' && !$this->safeHref($child->getAttribute('href'))) {   // javascript:, data:, relative, malformed …
                $this->clean($child, $depth + 1);
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }

            if ($tag === 'img' && !$this->safeImageSrc(trim($child->getAttribute('src')))) {   // email profile only: unsafe/unknown image source → removed
                $node->removeChild($child);
                continue;
            }

            $style = $child->getAttribute('style');
            $class = $child->getAttribute('class');
            $href  = trim($child->getAttribute('href'));
            $extra = $this->profile === self::PROFILE_EMAIL ? $this->cleanAttrs($tag, $child) : [];
            $src   = $tag === 'img' ? trim($child->getAttribute('src')) : '';
            foreach (iterator_to_array($child->attributes) as $attr) $child->removeAttribute($attr->name);

            if ($style !== '' && ($clean = $this->cleanStyle($style)) !== '') $child->setAttribute('style', $clean);
            if ($class !== '' && ($clean = $this->cleanClass($class)) !== '') $child->setAttribute('class', $clean);
            if ($tag === 'a') {                                                       // only a validated href survives; rel/target are ours
                $child->setAttribute('href', $href);
                $child->setAttribute('rel', 'noopener noreferrer nofollow');
                $child->setAttribute('target', '_blank');
            }
            if ($tag === 'img') $child->setAttribute('src', $src);
            foreach ($extra as $name => $value) $child->setAttribute($name, $value);  // validated numeric / keyword / plain-text attributes

            $this->clean($child, $depth + 1);
        }
    }

    protected function cleanStyle(string $style): string
    {
        $out = [];
        foreach (explode(';', $style) as $decl) {
            if (!str_contains($decl, ':')) continue;
            [$prop, $val] = array_map('trim', explode(':', $decl, 2));
            $prop = strtolower($prop);
            $val  = strtolower($val);

            $ok = match ($prop) {
                'font-size'     => (bool) preg_match('/^(\d{1,2})(\.\d)?(px|pt)$/', $val) && (float) $val >= 8 && (float) $val <= 48,
                'text-align'    => in_array($val, ['left', 'right', 'center', 'justify'], true),
                'font-family'   => isset(self::FONTS[trim($val, " \"'")]),
                'color',
                'background-color' => $this->safeColor($val),
                'line-height'   => (bool) preg_match('/^[1-3](\.\d{1,2})?$/', $val),
                'margin-top',
                'margin-bottom' => (bool) preg_match('/^(\d{1,2})(px)$/', $val) && (int) $val <= 48,
                default         => $this->profile === self::PROFILE_EMAIL && $this->emailStyleOk($prop, preg_replace('/\s+/', ' ', $val) ?? ''),
            };
            if (!$ok) continue;
            $out[$prop] = $prop === 'font-family' ? 'font-family:' . self::FONTS[trim($val, " \"'")] : "{$prop}:" . ($this->profile === self::PROFILE_EMAIL ? (preg_replace('/\s+/', ' ', $val) ?? '') : $val);
        }
        return implode(';', $out);
    }

    /** Only the editor's own indent classes survive (ql-indent-1 … ql-indent-8). */
    protected function cleanClass(string $class): string
    {
        $keep = array_filter(preg_split('/\s+/', trim($class)) ?: [], fn ($c) => (bool) preg_match('/^ql-indent-[1-8]$/', $c));
        return implode(' ', array_unique($keep));
    }

    /** Extra, individually validated style properties for the email profile. Never url(), expression(), var(), position, @import. */
    protected function emailStyleOk(string $prop, string $val): bool
    {
        return match ($prop) {
            'font-weight'     => in_array($val, ['normal', 'bold', 'bolder', 'lighter', '100', '200', '300', '400', '500', '600', '700', '800', '900'], true),
            'font-style'      => in_array($val, ['normal', 'italic'], true),
            'text-decoration' => in_array($val, ['none', 'underline', 'line-through'], true),
            'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'margin', 'margin-left', 'margin-right' => $this->boxValue($val),
            'border'          => (bool) preg_match('/^[0-5]px (solid|dashed|dotted) #([0-9a-f]{3}|[0-9a-f]{6})$/', $val),
            'width', 'max-width' => preg_match('/^(\d{1,4})(px|%)$/', $val, $m) === 1 && ($m[2] === '%' ? (int) $m[1] <= 100 : (int) $m[1] <= 1200),
            default           => false,
        };
    }

    /** 1–4 space-separated lengths, each 0–100 (optionally with px), or the single keyword auto. */
    protected function boxValue(string $val): bool
    {
        if ($val === 'auto') return true;
        $parts = explode(' ', $val);
        if (count($parts) < 1 || count($parts) > 4) return false;
        foreach ($parts as $p) {
            if (preg_match('/^(\d{1,3})(px)?$/', $p, $m) !== 1 || (int) $m[1] > 100) return false;
        }
        return true;
    }

    /** Validated plain attributes for the email profile: returns [name => safe value] for this tag. */
    protected function cleanAttrs(string $tag, DOMElement $el): array
    {
        $out = [];
        foreach (self::EMAIL_ATTRS[$tag] ?? [] as $name) {
            if (!$el->hasAttribute($name)) continue;
            $v = trim($el->getAttribute($name));
            $ok = match ($name) {
                'alt'                  => true,
                'width', 'height'      => $tag === 'img'
                    ? (preg_match('/^\d{1,4}$/', $v) === 1 && (int) $v >= 1 && (int) $v <= 2000)
                    : ($name === 'width' && preg_match('/^(\d{1,4})(%?)$/', $v, $m) === 1 && ($m[2] === '%' ? (int) $m[1] <= 100 : (int) $m[1] <= 1200)),
                'colspan', 'rowspan'   => preg_match('/^\d{1,2}$/', $v) === 1 && (int) $v >= 1 && (int) $v <= 20,
                'cellpadding', 'cellspacing' => preg_match('/^\d{1,2}$/', $v) === 1 && (int) $v <= 50,
                'border'               => preg_match('/^[0-5]$/', $v) === 1,
                'align'                => in_array(strtolower($v), ['left', 'center', 'right', 'justify'], true),
                'valign'               => in_array(strtolower($v), ['top', 'middle', 'bottom'], true),
                default                => false,
            };
            if (!$ok) continue;
            $out[$name] = $name === 'alt' ? $this->plain($v, 200) : (in_array($name, ['align', 'valign'], true) ? strtolower($v) : $v);
        }
        return $out;
    }

    /**
     * Image sources: an https URL, or a raster data: URI (png/jpeg/gif/webp — never svg), size-capped. Nothing else
     * (no http:, no javascript:, no relative paths, no svg). Fail CLOSED on regex errors.
     */
    protected function safeImageSrc(string $src): bool
    {
        if ($this->profile !== self::PROFILE_EMAIL || $src === '') return false;

        if (str_starts_with(strtolower($src), 'data:')) {
            return strlen($src) <= self::MAX_DATA_IMAGE
                && preg_match('#^data:image/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/]++={0,2}$#', $src) === 1;
        }

        if (strlen($src) > 2048) return false;
        if (preg_match('/[\x00-\x20\x7F<>"\'`\\\\]/', $src) !== 0) return false;
        return preg_match('#^https://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?([/?\#]|$)#i', $src) === 1;
    }

    /** #rgb, #rrggbb or rgb(r, g, b) with each channel 0–255. No names, no functions, no url(), no var(). */
    protected function safeColor(string $v): bool
    {
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $v) === 1) return true;
        if (preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/', $v, $m) === 1) {
            return $m[1] <= 255 && $m[2] <= 255 && $m[3] <= 255;
        }
        return false;
    }

    /** http(s) and mailto only, one token, no whitespace/control characters (browsers ignore tabs/newlines inside a scheme). */
    protected function safeHref(string $href): bool
    {
        $href = trim($href);
        if ($href === '' || strlen($href) > 2048) return false;
        // PHP single-quoted: '\\\\' is ONE backslash in the pattern, so the class below is  [ctrl/space DEL < > " ' ` \ ]
        // Fail CLOSED: `!== 0` is also true when preg_match errors (returns false) — an unsafe link is never kept.
        if (preg_match('/[\x00-\x20\x7F<>"\'`\\\\]/', $href) !== 0) return false;
        return preg_match('#^(https?://[^/]|mailto:[^@\s]+@)#i', $href) === 1;
    }
}
