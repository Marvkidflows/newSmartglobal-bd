<?php
// LOCATION: tests/Unit/EmailImageEmbedderTest.php
//
// data: images are converted to inline (cid:) attachments only when mail is being SENT (clients block data: URIs); everything else is left alone.

namespace Tests\Unit;

use App\Services\EmailImageEmbedder;
use App\Services\RichTextSanitizer;
use PHPUnit\Framework\TestCase;

class EmailImageEmbedderTest extends TestCase
{
    protected const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function fakeMessage(): object
    {
        return new class {
            public array $parts = [];
            public function embedData($data, $name, $contentType = null) { $this->parts[] = [$name, $contentType, strlen($data)]; return 'cid:' . $name; }
        };
    }

    public function test_data_images_become_cid_parts_and_https_images_and_text_are_left_alone(): void
    {
        $html = (new RichTextSanitizer())->sanitizeEmail('<p>Hi <img src="' . self::PNG . '" alt="px" width="10"> <img src="https://cdn.example.com/a.png" alt="A"></p>');
        $msg  = $this->fakeMessage();
        $out  = (new EmailImageEmbedder())->embed($html, $msg);

        $this->assertStringContainsString('src="cid:inline-image-1.png"', $out);
        $this->assertStringNotContainsString('data:image', $out);
        $this->assertStringContainsString('src="https://cdn.example.com/a.png"', $out);
        $this->assertStringContainsString('alt="px"', $out);                      // alt text stays, so a blocked image still says what it was
        $this->assertSame([['inline-image-1.png', 'image/png', strlen(base64_decode(substr(self::PNG, strlen('data:image/png;base64,'))))]], $msg->parts);
    }

    public function test_it_never_breaks_sending(): void
    {
        $e = new EmailImageEmbedder();
        $this->assertSame('<p>x</p>', $e->embed('<p>x</p>', $this->fakeMessage()));                       // nothing to embed
        $this->assertSame('', $e->embed('', $this->fakeMessage()));
        $html = '<img src="' . self::PNG . '">';
        $this->assertSame($html, $e->embed($html, new \stdClass()));                                       // no embedData() → unchanged
        $this->assertSame($html, $e->embed($html, null));
        $bad = '<img src="data:image/png;base64,@@@">';
        $this->assertSame($bad, $e->embed($bad, $this->fakeMessage()));                                    // not valid base64 → unchanged
    }

    public function test_the_number_of_embedded_images_is_capped(): void
    {
        $html = str_repeat('<img src="' . self::PNG . '">', EmailImageEmbedder::MAX_IMAGES + 3);
        $msg  = $this->fakeMessage();
        $out  = (new EmailImageEmbedder())->embed($html, $msg);
        $this->assertCount(EmailImageEmbedder::MAX_IMAGES, $msg->parts);
        $this->assertSame(3, substr_count($out, 'data:image/png'));
    }
}
