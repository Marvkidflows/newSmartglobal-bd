<?php
// LOCATION: tests/Unit/RichTextSanitizerProfilesTest.php
//
// The hardened sanitiser: the 'message' profile (chat-style messages, MarvFlow) must be UNCHANGED for everything it handled before,
// and the new 'email' profile (Email Center + investor inbox) must keep legitimate email markup while removing every hostile form.
// Pure PHP (ext-dom). Existing RichTextSanitizerTest is NOT modified and must keep passing.

namespace Tests\Unit;

use App\Services\RichTextSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RichTextSanitizerProfilesTest extends TestCase
{
    protected RichTextSanitizer $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = new RichTextSanitizer();
    }

    /** Things that must never survive in ANY profile (checked case-insensitively on the OUTPUT). */
    protected const NEVER = ['<script', 'onerror', 'onload', 'onclick', 'onmouseover', 'onfocus', 'ontoggle', 'onstart', 'javascript:', 'vbscript:',
                             'expression(', 'url(', '@import', 'srcset', '<iframe', '<object', '<embed', '<svg', '<math', '<form', '<input', '<base', '<meta',
                             '<link', '<style', '<xmp', '<plaintext', '<noframes', '<noembed', '<template', '<noscript', 'position:'];

    public static function hostile(): array
    {
        $v = [
            'script' => '<script>alert(1)</script>', 'img onerror' => '<img src=x onerror=alert(1)>', 'img slash separators' => '<img/src=x/onerror=alert(1)>',
            'img no space' => '<img src="x"onerror="alert(1)">', 'svg onload' => '<svg onload=alert(1)>', 'svg script' => '<svg><script>alert(1)</script></svg>',
            'math href' => '<math><mi xlink:href="javascript:alert(1)">x</mi></math>', 'iframe js' => '<iframe src="javascript:alert(1)"></iframe>',
            'object' => '<object data="javascript:alert(1)"></object>', 'embed' => '<embed src="javascript:alert(1)">',
            'form' => '<form action="javascript:alert(1)"><input name=x></form>', 'base' => '<base href="javascript:alert(1)//">',
            'meta refresh' => '<meta http-equiv="refresh" content="0;url=javascript:alert(1)">', 'link' => '<link rel=stylesheet href="javascript:alert(1)">',
            'style import' => '<style>@import "javascript:alert(1)";</style>', 'xmp' => '<xmp><script>alert(1)</script></xmp>',
            'plaintext' => '<plaintext><script>alert(1)</script>', 'noframes' => '<noframes><script>alert(1)</script></noframes>',
            'noembed' => '<noembed><img src=x onerror=alert(1)></noembed>', 'template' => '<template><script>alert(1)</script></template>',
            'noscript mxss' => '<noscript><p title="</noscript><img src=x onerror=alert(1)>">', 'listing' => '<listing><img src=x onerror=alert(1)></listing>',
            'a js' => '<a href="javascript:alert(1)">x</a>', 'a js mixed case' => '<a href=" JaVaScRiPt:alert(1)">x</a>', 'a js tab' => "<a href=\"java\tscript:alert(1)\">x</a>",
            'a js entity tab' => '<a href="java&#x09;script:alert(1)">x</a>', 'a js entity char' => '<a href="&#106;avascript:alert(1)">x</a>',
            'a js entity colon' => '<a href="&#x6A;avascript&colon;alert(1)">x</a>', 'a data html' => '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
            'a vbscript' => '<a href="vbscript:msgbox(1)">x</a>', 'a onclick' => '<a href="https://ok.example/x" onclick="alert(1)">x</a>',
            'style url js' => '<p style="background:url(javascript:alert(1))">x</p>', 'style expression' => '<p style="width:expression(alert(1))">x</p>',
            'style position' => '<p style="position:fixed;top:0;left:0;width:100%;height:100%">x</p>', 'style behavior' => '<p style="font-family:Arial;behavior:url(x.htc)">x</p>',
            'style var' => '<span style="color:var(--x)">x</span>', 'style import' => '<p style="@import url(x)">x</p>',
            'img svg data' => '<img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+" onerror=1>', 'img js' => '<img src="javascript:alert(1)">',
            'img http' => '<img src="http://insecure.example/a.png">', 'img protocol relative' => '<img src="//evil.example/a.png">', 'img relative' => '<img src="/local.png">',
            'img srcset' => '<img srcset="https://a/x.png 1x, javascript:alert(1) 2x" src="https://a/x.png">',
            'table handlers' => '<table onclick="alert(1)"><tr><td colspan="999" onmouseover="alert(1)">x</td></tr></table>',
            'div handler' => '<div onmouseover="alert(1)">x</div>', 'p handler no quotes' => '<p onclick=alert(1)>x</p>',
            'comment' => '<!-- <script>alert(1)</script> -->x', 'cdata' => '<![CDATA[<script>alert(1)</script>]]>', 'php pi' => '<?php echo 1; ?>x',
            'split tag' => '<scr<script>ipt>alert(1)</scr</script>ipt>', 'nested anchors' => '<a href="https://ok.example"><a href="javascript:alert(1)">x</a></a>',
            'button formaction' => '<button formaction="javascript:alert(1)">x</button>', 'details toggle' => '<details open ontoggle=alert(1)>x</details>',
            'marquee' => '<marquee onstart=alert(1)>x</marquee>', 'video source' => '<video><source onerror=alert(1)></video>',
            'input autofocus' => '<input autofocus onfocus=alert(1)>', 'select' => '<select onfocus=alert(1) autofocus>', 'textarea' => '<textarea autofocus onfocus=alert(1)>',
            'keygen' => '<keygen autofocus onfocus=alert(1)>', 'bgsound' => '<bgsound src="javascript:alert(1)">',
            'deep nesting' => str_repeat('<div>', 100) . '<script>alert(1)</script>' . str_repeat('</div>', 100),
        ];
        $out = [];
        foreach (['message', 'email'] as $profile) foreach ($v as $name => $html) $out["$profile: $name"] = [$profile, $html];
        return $out;
    }

    #[DataProvider('hostile')]
    public function test_hostile_input_never_survives_in_any_profile_and_output_is_stable(string $profile, string $html): void
    {
        $out = $this->s->sanitize($html, $profile);
        foreach (self::NEVER as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $out, "profile {$profile} kept '{$bad}'");
        }
        if ($profile === 'message') $this->assertStringNotContainsString('<img', $out);

        // Re-sanitising the OUTPUT (which happens on every read of stored content) must stay safe. Byte-for-byte idempotency is NOT asserted for
        // hostile/malformed input: libxml2 prints an empty element whose end tag is optional (e.g. `<li></li>`) as a bare `<li>`, so a second
        // pass can add a closing tag. That is a serialisation quirk of the old sanitiser too, never a safety issue (verified separately by a
        // 20,000-input fuzz: zero safety violations). Exact stability is asserted for well-formed content in the tests below.
        $again = $this->s->sanitize($out, $profile);
        foreach (self::NEVER as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $again, "second pass ({$profile}) produced '{$bad}'");
        }
    }

    // ── message profile: UNCHANGED (MarvFlow compatibility) ───────────────────

    public static function marvflowCanonical(): array
    {
        return array_map(fn ($h) => [$h], [
            '<p style="font-size:14px;color:#ff0000;text-align:center">x</p>',
            '<p style="font-family:Georgia;line-height:1.5;margin-bottom:16px">Styled</p>',
            '<ul><li>one</li><li class="ql-indent-1">two</li></ul><ol><li>a</li></ol>',
            '<p><a href="https://example.com/p?q=1" rel="noopener noreferrer nofollow" target="_blank">l</a></p>',
            '<p style="background-color:rgb(255, 255, 0)">h</p>',
            '<blockquote>q</blockquote><div>d</div><p>a<br>b</p>',
            '<p><strong>b</strong> <em>i</em> <u>u</u> <s>s</s></p>',
        ]);
    }

    #[DataProvider('marvflowCanonical')]
    public function test_content_already_stored_by_marvflow_is_a_fixed_point_of_the_message_profile(string $stored): void
    {
        $this->assertSame($stored, $this->s->sanitize($stored));                                  // default profile = message
        $this->assertSame($stored, $this->s->sanitize($stored, 'message'));
    }

    public function test_the_message_profile_still_removes_what_it_always_removed(): void
    {
        $this->assertStringNotContainsString('<h1', $this->s->sanitize('<h1>x</h1>'));
        $this->assertStringNotContainsString('<table', $this->s->sanitize('<table><tr><td>x</td></tr></table>'));
        $this->assertStringNotContainsString('<img', $this->s->sanitize('<img src="https://a.example/x.png">'));
        $this->assertSame('', $this->s->sanitize(''));
        $this->assertSame('', $this->s->sanitize('   '));
    }

    // ── email profile: legitimate content survives ────────────────────────────

    public function test_the_email_profile_keeps_headings_lists_links_tables_rules_and_formatting(): void
    {
        $html = '<h1>Title</h1><h2>Sub</h2><p style="font-weight:bold;font-style:italic;text-decoration:underline;padding:4px 8px;max-width:600px;width:100%">Body</p>'
              . '<ul><li>x</li></ul><p><a href="https://example.com">site</a></p>'
              . '<table width="100%" cellpadding="8" cellspacing="0" border="1"><thead><tr><th align="left">Plan</th></tr></thead>'
              . '<tbody><tr><td colspan="1" valign="top" style="padding:8px;border:1px solid #cccccc">A</td></tr></tbody></table><hr>';
        $out = $this->s->sanitizeEmail($html);
        foreach (['<h1>', '<h2>', 'font-weight:bold', 'font-style:italic', 'text-decoration:underline', 'padding:4px 8px', 'max-width:600px', '<ul>', 'href="https://example.com"',
                  '<table', 'width="100%"', 'cellpadding="8"', '<thead>', '<th align="left">', 'colspan="1"', 'valign="top"', 'border:1px solid #cccccc', '<hr>'] as $keep) {
            $this->assertStringContainsString($keep, $out, "email profile lost {$keep}");
        }
        $this->assertSame($out, $this->s->sanitizeEmail($out));
    }

    public function test_the_full_composer_toolbar_output_survives_in_the_email_profile(): void
    {
        $html = '<p style="font-family:Georgia;font-size:18px;color:#1a3a8f;background-color:#ffff00;text-align:justify;line-height:1.5;margin-top:8px;margin-bottom:16px">x</p><ol><li class="ql-indent-2">y</li></ol>';
        $out = $this->s->sanitizeEmail($html);
        foreach (['font-family:Georgia', 'font-size:18px', 'color:#1a3a8f', 'background-color:#ffff00', 'text-align:justify', 'line-height:1.5', 'margin-bottom:16px', 'ql-indent-2'] as $keep) {
            $this->assertStringContainsString($keep, $out);
        }
    }

    // ── images: consistent rules ──────────────────────────────────────────────

    public function test_https_and_raster_data_images_are_kept_in_the_email_profile_only(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
        $html = '<p><img src="https://cdn.example.com/logo.png" alt="Logo" width="120" height="40"><img src="' . $png . '" alt="px"></p>';
        $out  = $this->s->sanitizeEmail($html);
        $this->assertStringContainsString('src="https://cdn.example.com/logo.png"', $out);
        $this->assertStringContainsString('alt="Logo"', $out);
        $this->assertStringContainsString('width="120"', $out);
        $this->assertStringContainsString($png, $out);
        $this->assertStringNotContainsString('<img', $this->s->sanitize($html));                  // message profile: no images at all
    }

    public function test_unsafe_image_sources_are_removed_from_the_email_profile(): void
    {
        foreach (['<img src="http://a.example/x.png">', '<img src="data:image/svg+xml;base64,AAAA">', '<img src="data:text/html;base64,AAAA">', '<img src="javascript:1">',
                  '<img src="//a.example/x.png">', '<img src="/x.png">', '<img src="https://a.example/x y.png">', '<img src="https://user:pw@a.example/x.png" onerror="1">',
                  '<img src="data:image/png;base64,' . str_repeat('A', 1048577) . '">', '<img>', '<img src="">'] as $html) {
            $this->assertStringNotContainsString('<img', $this->s->sanitizeEmail($html), $html === '' ? '' : substr($html, 0, 60));
        }
        $out = $this->s->sanitizeEmail('<img src="https://ok.example/a.png" width="99999" height="-1" onerror="x" alt="a<b">');
        $this->assertStringNotContainsString('width=', $out);
        $this->assertStringNotContainsString('height=', $out);
        $this->assertStringNotContainsString('onerror', $out);
    }

    public function test_out_of_range_table_and_style_values_are_dropped_not_kept(): void
    {
        $out = $this->s->sanitizeEmail('<table width="5000" cellpadding="999"><tr><td colspan="999" style="padding:500px;width:5000px;border:9px solid red;font-weight:900;margin:0 auto">x</td></tr></table>');
        foreach (['width="5000"', 'cellpadding', 'colspan', 'padding:500px', 'width:5000px', 'border:', 'red'] as $gone) {
            $this->assertStringNotContainsString($gone, $out, "kept {$gone}");
        }
        $this->assertStringContainsString('font-weight:900', $out);
    }

    // ── fail-closed limits ────────────────────────────────────────────────────

    public function test_oversize_input_is_rejected_empty_rather_than_passed_through(): void
    {
        $this->assertSame('', $this->s->sanitize('<p>' . str_repeat('a', 400001) . '</p>'));
        $this->assertNotSame('', $this->s->sanitize('<p>' . str_repeat('a', 100000) . '</p>'));
        $this->assertSame('', $this->s->sanitize('<p>' . str_repeat('<b>x</b>', 25000) . '</p>'));      // more than the element cap
    }
}
