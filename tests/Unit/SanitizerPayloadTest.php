<?php
// LOCATION: tests/Unit/SanitizerPayloadTest.php
// F-11: malicious HTML/JS payload battery against BOTH sanitiser profiles. STATUS: written, NOT executed by the author.

namespace Tests\Unit;

use App\Services\RichTextSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SanitizerPayloadTest extends TestCase
{
    public static function payloads(): array
    {
        return [
            'script tag'            => ['<p>hi</p><script>alert(1)</script>'],
            'img onerror'           => ['<img src=x onerror=alert(1)>'],
            'svg onload'            => ['<svg onload=alert(1)><circle/></svg>'],
            'js href'               => ['<a href="javascript:alert(1)">x</a>'],
            'js href entity'        => ['<a href="jav&#x09;ascript:alert(1)">x</a>'],
            'js href case/space'    => ['<a href=" JaVaScRiPt:alert(1)">x</a>'],
            'data html href'        => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>'],
            'vbscript href'         => ['<a href="vbscript:msgbox(1)">x</a>'],
            'iframe'                => ['<iframe src="https://evil.example"></iframe>'],
            'object/embed'          => ['<object data="x"></object><embed src="x">'],
            'style expression'      => ['<p style="width:expression(alert(1))">x</p>'],
            'style url js'          => ['<p style="background:url(javascript:alert(1))">x</p>'],
            'event handler on p'    => ['<p onclick="alert(1)" onmouseover="alert(2)">x</p>'],
            'form'                  => ['<form action="https://evil.example"><input name=pw></form>'],
            'meta refresh'          => ['<meta http-equiv="refresh" content="0;url=javascript:alert(1)">'],
            'base tag'              => ['<base href="https://evil.example/">'],
            'nested mutation xss'   => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>">'],
            'math'                  => ['<math><mtext><script>alert(1)</script></mtext></math>'],
            'template'              => ['<template><script>alert(1)</script></template>'],
            'comment smuggling'     => ['<!--><script>alert(1)</script>-->'],
            'unclosed attr'         => ['<a href="https://ok.example" onmouseover=alert(1) x="'],
            'srcdoc'                => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>'],
        ];
    }

    #[DataProvider('payloads')]
    public function test_message_profile_never_emits_executable_content(string $payload): void
    {
        $out = (new RichTextSanitizer())->sanitize($payload);
        $this->assertNoExecutable($out, $payload);
    }

    #[DataProvider('payloads')]
    public function test_email_profile_never_emits_executable_content(string $payload): void
    {
        $out = (new RichTextSanitizer())->sanitizeEmail($payload);
        $this->assertNoExecutable($out, $payload);
    }

    public function test_safe_links_survive_with_forced_rel_and_target(): void
    {
        $out = (new RichTextSanitizer())->sanitize('<a href="https://example.com/p?q=1">ok</a>');
        $this->assertStringContainsString('https://example.com/p?q=1', $out);
        $this->assertStringContainsString('noopener', $out);
    }

    public function test_output_is_idempotent(): void
    {
        $s = new RichTextSanitizer();
        foreach (self::payloads() as [$p]) { $once = $s->sanitize($p . '<p><b>bold</b></p>'); $this->assertSame($once, $s->sanitize($once)); }
    }

    protected function assertNoExecutable(string $out, string $in): void
    {
        $l = strtolower($out);
        foreach (['<script', 'onerror', 'onload', 'onclick', 'onmouseover', 'javascript:', 'vbscript:', 'data:text/html', '<iframe', '<object', '<embed', '<form', '<meta', '<base', 'expression(', 'srcdoc'] as $needle) {
            $this->assertStringNotContainsString($needle, $l, "'{$needle}' survived for input: {$in}\noutput: {$out}");
        }
    }
}
