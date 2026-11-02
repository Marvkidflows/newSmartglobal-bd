<?php
// LOCATION: tests/Unit/RichTextSanitizerTest.php
//
// RichTextSanitizer is the ONLY sanitiser for MarvFlow rich text. These tests pin both halves of its contract:
// everything the composer can produce survives, and everything unsafe is removed. Pure PHP (needs ext-dom only).

namespace Tests\Unit;

use App\Services\RichTextSanitizer;
use PHPUnit\Framework\TestCase;

class RichTextSanitizerTest extends TestCase
{
    protected RichTextSanitizer $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = new RichTextSanitizer();
    }

    // ── what the composer produces must survive ────────────────────────────────

    public function test_a_typical_quill_message_is_preserved(): void
    {
        $in = '<p style="text-align: center; line-height: 1.5; margin-bottom: 16px;"><span style="font-size: 18px; font-family: Georgia; color: rgb(230, 0, 0); background-color: #ffff00;">'
            . '<strong><em><u><s>styled</s></u></em></strong></span></p><p class="ql-indent-1">indented</p><ol><li class="ql-indent-1">item</li></ol><ul><li>dot</li></ul>';
        $out = $this->s->sanitize($in);

        foreach (['text-align:center', 'line-height:1.5', 'margin-bottom:16px', 'font-size:18px', 'font-family:Georgia', 'color:rgb(230, 0, 0)',
                  'background-color:#ffff00', '<strong>', '<em>', '<u>', '<s>', 'class="ql-indent-1"', '<ol>', '<ul>', 'styled', 'indented'] as $keep) {
            $this->assertStringContainsString($keep, $out, "lost: {$keep}");
        }
    }

    public function test_safe_links_are_kept_and_hardened(): void
    {
        $out = $this->s->sanitize('<a href="https://example.com/a?b=1&c=2" onclick="x()" style="color:red" target="_self" class="evil">Site</a> <a href="mailto:team@ssi.com">mail</a>');

        $this->assertStringContainsString('href="https://example.com/a?b=1&amp;c=2"', $out);
        $this->assertStringContainsString('href="mailto:team@ssi.com"', $out);
        $this->assertSame(2, substr_count($out, 'rel="noopener noreferrer nofollow"'));
        $this->assertSame(2, substr_count($out, 'target="_blank"'));
        foreach (['onclick', '_self', 'evil', 'color:red'] as $bad) $this->assertStringNotContainsString($bad, $out);
    }

    // ── what must never survive ────────────────────────────────────────────────

    public function test_unsafe_links_are_unwrapped_but_their_text_is_kept(): void
    {
        foreach ([
            'javascript:alert(1)', 'JaVaScRiPt:alert(1)', "java\nscript:alert(1)", "java\tscript:alert(1)", ' javascript:alert(1)', 'data:text/html;base64,PHNjcmlwdD4=',
            'vbscript:msgbox(1)', '//evil.example/x', '/relative/path', 'ftp://example.com/x', 'https://x.com/a b', 'https://x.com/"onmouseover=alert(1)',
            "https://x.com/'x", 'https://x.com/<s>', 'https://x.com/`x', 'https://x.com/\\x', '', 'https:///nohost', 'https://' . str_repeat('a', 3000),
        ] as $href) {
            $out = $this->s->sanitize('<p>before <a href="' . htmlspecialchars($href, ENT_QUOTES) . '">LINKTEXT</a> after</p>');
            $this->assertStringNotContainsString('<a', $out, "link survived: " . substr($href, 0, 40));
            $this->assertStringContainsString('LINKTEXT', $out);
        }
    }

    public function test_only_validated_colours_and_fonts_are_accepted(): void
    {
        $ok = $this->s->sanitize('<span style="color:#abc;background-color:rgb(1, 2, 3);font-family:\'Arial\'">x</span>');
        $this->assertStringContainsString('color:#abc', $ok);
        $this->assertStringContainsString('background-color:rgb(1, 2, 3)', $ok);
        $this->assertStringContainsString('font-family:Arial', $ok);

        foreach (['color:red', 'color:#12345678', 'color:#gg0000', 'color:rgb(999,0,0)', 'color:rgba(0,0,0,.5)', 'color:var(--x)', 'color:expression(alert(1))',
                  'background-color:url(javascript:alert(1))', 'background:url(x)', 'font-family:"Times New Roman"', 'font-family:evil', 'font-family:Arial,serif',
                  'position:fixed', 'z-index:9999', 'font-size:500px', 'font-size:2px', 'margin-left:-50px', 'margin-bottom:200px', 'line-height:99'] as $style) {
            $out = $this->s->sanitize('<span style="' . $style . '">x</span>');
            $this->assertStringNotContainsString('style=', $out, "style survived: {$style}");
        }

        // a hostile declaration cannot smuggle a second one past the filter
        $mixed = $this->s->sanitize('<span style="font-family: Arial;position:fixed;top:0;color:#fff">x</span>');
        $this->assertSame('<span style="font-family:Arial;color:#fff">x</span>', $mixed);
    }

    public function test_only_the_editors_indent_classes_survive(): void
    {
        $this->assertSame('<p class="ql-indent-2">a</p>', $this->s->sanitize('<p class="ql-indent-2 evil other">a</p>'));
        $this->assertSame('<p>a</p>', $this->s->sanitize('<p class="ql-indent-9">a</p>'));
        $this->assertSame('<p>a</p>', $this->s->sanitize('<p class="admin-only">a</p>'));
    }

    public function test_scripts_handlers_and_dangerous_elements_are_removed(): void
    {
        $out = $this->s->sanitize('<p onclick="a()" onmouseover="b()">Hi<script>alert(1)</script><img src=x onerror=alert(1)><iframe src="//e"></iframe>'
            . '<svg onload=alert(1)></svg><style>*{display:none}</style><object data=x></object><form><input name=a></form><!-- c --><video src=x></video>'
            . '<math><mi>x</mi></math><base href="//evil"><meta http-equiv="refresh" content="0"></p>');

        foreach (['<script', '<img', '<iframe', '<svg', '<style', '<object', '<form', '<input', '<video', '<math', '<base', '<meta', 'onclick', 'onmouseover', 'onerror', 'onload', '<!--', 'alert(1)'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $out, "survived: {$bad}");
        }
        $this->assertStringContainsString('Hi', $out);
    }

    public function test_unknown_wrappers_are_unwrapped_and_their_text_kept(): void
    {
        $out = $this->s->sanitize('<h1>Heading</h1><font color="red">font</font><table><tr><td>cell</td></tr></table><strike>old</strike>');
        foreach (['<h1', '<font', '<table', '<td', '<strike'] as $tag) $this->assertStringNotContainsString($tag, $out);
        foreach (['Heading', 'font', 'cell', 'old'] as $text) $this->assertStringContainsString($text, $out);
    }

    public function test_sanitising_is_idempotent_and_handles_empty_input(): void
    {
        $in = '<p style="text-align:center;position:fixed"><a href="https://example.com/x" onclick="x()">l</a><script>1</script><strong>b</strong></p><p class="ql-indent-3 z">i</p>';
        $once = $this->s->sanitize($in);
        $this->assertSame($once, $this->s->sanitize($once));
        $this->assertSame('', $this->s->sanitize('   '));
        $this->assertSame('', $this->s->sanitize('<script>alert(1)</script>'));
    }

    public function test_plain_text_helpers(): void
    {
        $this->assertSame('Hi there', $this->s->plain("<b>Hi</b>\n<script>alert(1)</script> there"));
        $this->assertSame('', $this->s->plain('<b></b>   '));
        $this->assertSame("one\ntwo", $this->s->toText('<p>one</p><p>two</p>'));
    }
}
