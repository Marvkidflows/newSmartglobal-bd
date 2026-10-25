<?php
// LOCATION: tests/Feature/TeamDocumentAttachmentTest.php
//
// MarvFlow team dashboard — private DOCUMENT attachments (PDF, DOCX, XLSX, TXT, MD) alongside the existing image path.
//
// Isolation: documents go to a FAKE private disk; the queue is faked (no Telegram job runs); Cloudinary credentials are
// cleared for the process so the existing image path can never reach the live image host (it then degrades exactly as
// it does in production when Cloudinary fails: the message is saved without an image URL).

namespace Tests\Feature;

use App\Models\TeamRequest;
use App\Models\TeamRequestAttachment;
use App\Models\TeamRequestMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class TeamDocumentAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $admin2;
    protected User $financial;
    protected User $member;
    protected User $investor;
    protected TeamRequest $req;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('team_documents');
        config(['team_documents.disk' => 'team_documents', 'team_documents.max_kb' => 10240]);
        Queue::fake();

        putenv('CLOUDINARY_URL');                                    // unset: the image path must not touch the network
        unset($_ENV['CLOUDINARY_URL'], $_SERVER['CLOUDINARY_URL']);

        $this->admin     = User::factory()->create(['role' => 'admin']);
        $this->admin2    = User::factory()->create(['role' => 'admin']);
        $this->financial = User::factory()->create(['role' => 'financial']);
        $this->member    = User::factory()->create(['role' => 'marvflow_member']);
        $this->investor  = User::factory()->create(['role' => 'investor']);

        $this->req = $this->makeRequest($this->admin);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    protected function makeRequest(User $sender, array $extra = []): TeamRequest
    {
        return TeamRequest::create($extra + [
            'sender_id' => $sender->id, 'subject' => 'Need a change', 'message' => 'Please look at this.',
            'priority' => 'normal', 'status' => 'new',
        ]);
    }

    protected function send(User $as, string $url, array $data)
    {
        return $this->actingAs($as, 'sanctum')->post($url, $data, ['Accept' => 'application/json']);
    }

    protected function pdf(string $name = 'spec.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    }

    protected function png(string $name = 'shot.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }

    /** A real ZIP container with the given entries (DOCX/XLSX are ZIPs). */
    protected function zip(string $name, array $entries): UploadedFile
    {
        if (!class_exists(ZipArchive::class)) $this->markTestSkipped('ext-zip is not available.');
        $path = tempnam(sys_get_temp_dir(), 'ooxml');
        $z = new ZipArchive();
        $z->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);
        foreach ($entries as $entry => $content) $z->addFromString($entry, $content);
        $z->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function docx(string $name = 'brief.docx', array $extra = []): UploadedFile
    {
        return $this->zip($name, $extra + [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            'word/document.xml'   => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>',
        ]);
    }

    protected function xlsx(string $name = 'budget.xlsx'): UploadedFile
    {
        return $this->zip($name, [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>',
            'xl/workbook.xml'     => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets/></workbook>',
        ]);
    }

    protected function replyUrl(string $side = 'marvflow', ?TeamRequest $r = null): string
    {
        $id = ($r ?? $this->req)->id;
        return match ($side) {
            'marvflow'  => "/api/marvflow/requests/{$id}/reply",
            'admin'     => "/api/admin/dev-requests/{$id}/reply",
            'financial' => "/api/financial/dev-requests/{$id}/reply",
        };
    }

    protected function assertNothingSaved(): void
    {
        $this->assertSame(0, TeamRequestMessage::count());
        $this->assertSame(0, TeamRequestAttachment::count());
        $this->assertSame([], Storage::disk('team_documents')->allFiles());
    }

    protected function uploadAsAdmin(UploadedFile $file): TeamRequestAttachment
    {
        $this->send($this->admin, $this->replyUrl('admin'), ['body' => 'Attached.', 'attachment' => $file])->assertOk();
        return TeamRequestAttachment::latest('id')->firstOrFail();
    }

    // ── Existing images keep working ───────────────────────────────────────────

    public function test_existing_image_attachments_are_still_accepted_and_do_not_go_to_document_storage(): void
    {
        $this->send($this->member, $this->replyUrl(), ['body' => 'Screenshot attached', 'attachment' => $this->png()])->assertOk();

        $this->assertSame(1, TeamRequestMessage::count());                       // the reply is saved (image host unreachable → no URL, as in production fallback)
        $this->assertSame(0, TeamRequestAttachment::count());                    // images are NOT stored as private documents
        $this->assertSame([], Storage::disk('team_documents')->allFiles());
    }

    public function test_legacy_image_attachments_remain_unchanged_in_the_api(): void
    {
        $old = $this->makeRequest($this->admin, ['attachment_url' => 'https://res.cloudinary.com/demo/old-screenshot.png']);
        $msg = TeamRequestMessage::create(['team_request_id' => $old->id, 'sender_id' => $this->member->id, 'sender_side' => 'marvflow',
            'body' => 'old reply', 'attachment_url' => 'https://res.cloudinary.com/demo/old-reply.png']);

        $res = $this->actingAs($this->member, 'sanctum')->getJson("/api/marvflow/requests/{$old->id}")->assertOk();
        $res->assertJsonPath('request.attachment_url', 'https://res.cloudinary.com/demo/old-screenshot.png')
            ->assertJsonPath('request.messages.0.attachment_url', 'https://res.cloudinary.com/demo/old-reply.png')
            ->assertJsonPath('request.attachments', [])
            ->assertJsonPath('request.messages.0.attachments', []);

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/dev-requests/{$old->id}")->assertOk()
            ->assertJsonPath('request.attachment_url', 'https://res.cloudinary.com/demo/old-screenshot.png');
    }

    public function test_an_oversized_image_is_still_rejected_at_the_original_5mb_limit(): void
    {
        $big = UploadedFile::fake()->createWithContent('huge.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==') . str_repeat("\0", 5300 * 1024));
        $this->send($this->member, $this->replyUrl(), ['body' => 'x', 'attachment' => $big])->assertStatus(422)->assertJsonValidationErrors('attachment');
        $this->assertNothingSaved();
    }

    // ── Valid documents ────────────────────────────────────────────────────────

    public function test_a_pdf_reply_saves_the_file_privately_with_correct_metadata_and_association(): void
    {
        $file = $this->pdf('Spec v1.pdf');
        $size = $file->getSize();

        $res = $this->send($this->member, $this->replyUrl(), ['body' => 'See the spec', 'attachment' => $file])->assertOk();

        $row = TeamRequestAttachment::sole();
        $this->assertSame($this->req->id, $row->team_request_id);
        $this->assertSame(TeamRequestMessage::sole()->id, $row->team_request_message_id);    // tied to THIS reply
        $this->assertSame($this->member->id, $row->uploader_id);
        $this->assertSame('Spec v1.pdf', $row->original_name);
        $this->assertSame('pdf', $row->extension);
        $this->assertSame('application/pdf', $row->mime_type);
        $this->assertSame($size, $row->size);
        $this->assertSame(64, strlen($row->sha256));
        $this->assertNotNull($row->created_at);

        $this->assertStringStartsWith("team-requests/{$this->req->id}/", $row->storage_path);
        $this->assertStringNotContainsString('Spec', $row->storage_path);                    // storage name is server-generated
        Storage::disk('team_documents')->assertExists($row->storage_path);

        $res->assertJsonPath('request.messages.0.attachments.0.name', 'Spec v1.pdf')
            ->assertJsonPath('request.messages.0.attachments.0.type', 'PDF')
            ->assertJsonPath('request.messages.0.attachments.0.uploaded_by', $this->member->name)
            ->assertJsonPath('request.messages.0.attachments.0.previewable', true)
            ->assertJsonMissingPath('request.messages.0.attachments.0.storage_path');       // never exposed
    }

    public function test_docx_xlsx_txt_and_markdown_uploads_succeed(): void
    {
        foreach ([
            ['brief.docx', fn () => $this->docx('brief.docx'), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['budget.xlsx', fn () => $this->xlsx('budget.xlsx'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['notes.txt', fn () => UploadedFile::fake()->createWithContent('notes.txt', "plain notes — ünïcode ok\n"), 'text/plain'],
            ['README.md', fn () => UploadedFile::fake()->createWithContent('README.md', "# Title\n\n- item\n"), 'text/markdown'],
        ] as [$name, $make, $mime]) {
            $this->send($this->member, $this->replyUrl(), ['body' => "with {$name}", 'attachment' => $make()])->assertOk();
            $row = TeamRequestAttachment::latest('id')->firstOrFail();
            $this->assertSame($name, $row->original_name);
            $this->assertSame($mime, $row->mime_type, $name);
            Storage::disk('team_documents')->assertExists($row->storage_path);
        }
        $this->assertSame(4, TeamRequestAttachment::count());
    }

    public function test_a_document_on_the_original_request_is_linked_to_the_request_not_a_reply(): void
    {
        $res = $this->send($this->admin, '/api/admin/dev-requests', [
            'subject' => 'Spec attached', 'message' => 'Please review', 'priority' => 'high', 'attachment' => $this->pdf('plan.pdf'),
        ])->assertStatus(201);

        $row = TeamRequestAttachment::sole();
        $this->assertNull($row->team_request_message_id);
        $this->assertSame($this->admin->id, $row->uploader_id);
        $this->assertSame((int) $res->json('request.id'), $row->team_request_id);
        $res->assertJsonPath('request.attachments.0.name', 'plan.pdf');
    }

    // ── Rejections ─────────────────────────────────────────────────────────────

    public function test_unsupported_unsafe_or_mismatched_files_are_rejected_and_nothing_is_saved(): void
    {
        $cases = [
            'executable'        => fn () => UploadedFile::fake()->createWithContent('run.exe', "MZ\x90\x00\x03"),
            'php script'        => fn () => UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET[1]); ?>'),
            'shell script'      => fn () => UploadedFile::fake()->createWithContent('go.sh', "#!/bin/sh\nrm -rf /\n"),
            'html'              => fn () => UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
            'svg'               => fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg onload="alert(1)"/>'),
            'archive'           => fn () => UploadedFile::fake()->createWithContent('bundle.zip', "PK\x03\x04"),
            'legacy doc'        => fn () => UploadedFile::fake()->createWithContent('old.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            'legacy xls'        => fn () => UploadedFile::fake()->createWithContent('old.xls', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            'macro docm'        => fn () => $this->docx('macro.docm'),
            'no extension'      => fn () => UploadedFile::fake()->createWithContent('README', 'hello'),
            'double extension'  => fn () => $this->pdf('report.pdf.exe'),
            'php renamed .pdf'  => fn () => UploadedFile::fake()->createWithContent('invoice.pdf', '<?php system($_GET[1]); ?>'),
            'binary as .txt'    => fn () => UploadedFile::fake()->createWithContent('data.txt', "a\0b\0c"),
            'latin1 as .txt'    => fn () => UploadedFile::fake()->createWithContent('latin.txt', "caf\xE9"),
            'empty pdf'         => fn () => UploadedFile::fake()->createWithContent('empty.pdf', ''),
            'zip renamed .docx' => fn () => $this->zip('fake.docx', ['readme.txt' => 'not an office file']),
            'docx with macros'  => fn () => $this->docx('macro.docx', ['word/vbaProject.bin' => 'binary macro']),
            'docx path trick'   => fn () => $this->docx('trick.docx', ['word/../../evil.xml' => 'x']),
        ];

        foreach ($cases as $label => $make) {
            $res = $this->send($this->member, $this->replyUrl(), ['body' => 'try', 'attachment' => $make()]);
            $res->assertStatus(422);
            $this->assertNotEmpty($res->json('errors.attachment'), "{$label}: expected a clear attachment error");
            $this->assertNothingSaved();
        }
    }

    public function test_unsupported_type_messages_are_clear(): void
    {
        $r = $this->send($this->member, $this->replyUrl(), ['body' => 'x', 'attachment' => UploadedFile::fake()->createWithContent('run.exe', 'MZ')]);
        $this->assertStringContainsString('Unsupported file type (.exe)', $r->json('errors.attachment.0'));

        $r = $this->send($this->member, $this->replyUrl(), ['body' => 'x', 'attachment' => UploadedFile::fake()->createWithContent('old.doc', 'x')]);
        $this->assertStringContainsString('docx', $r->json('errors.attachment.0'));
    }

    public function test_the_document_size_limit_is_configurable_and_enforced(): void
    {
        config(['team_documents.max_kb' => 1]);
        $big = UploadedFile::fake()->createWithContent('big.pdf', "%PDF-1.4\n" . str_repeat('A', 3000) . "\n%%EOF");

        $r = $this->send($this->member, $this->replyUrl(), ['body' => 'x', 'attachment' => $big])->assertStatus(422);
        $this->assertStringContainsString('too large', $r->json('errors.attachment.0'));
        $this->assertNothingSaved();

        config(['team_documents.max_kb' => 10240]);
        $this->send($this->member, $this->replyUrl(), ['body' => 'x', 'attachment' => $big])->assertOk();   // same file now fits
    }

    // ── Authentication & authorisation ─────────────────────────────────────────

    public function test_unauthenticated_users_cannot_upload_or_download(): void
    {
        $row = $this->uploadAsAdmin($this->pdf());
        $this->app['auth']->forgetGuards();

        $this->post($this->replyUrl(), ['body' => 'x', 'attachment' => $this->pdf()], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->getJson("/api/marvflow/requests/{$this->req->id}/attachments/{$row->id}")->assertUnauthorized();
        $this->getJson("/api/admin/dev-requests/{$this->req->id}/attachments/{$row->id}")->assertUnauthorized();
        $this->getJson("/api/financial/dev-requests/{$this->req->id}/attachments/{$row->id}")->assertUnauthorized();
    }

    public function test_roles_without_access_cannot_upload_or_download(): void
    {
        $row = $this->uploadAsAdmin($this->pdf());

        $this->send($this->investor, $this->replyUrl('marvflow'), ['body' => 'x', 'attachment' => $this->pdf()])->assertForbidden();
        $this->send($this->investor, $this->replyUrl('admin'),    ['body' => 'x', 'attachment' => $this->pdf()])->assertForbidden();
        $this->send($this->financial, $this->replyUrl('marvflow'), ['body' => 'x', 'attachment' => $this->pdf()])->assertForbidden();
        $this->send($this->member, $this->replyUrl('admin'),      ['body' => 'x', 'attachment' => $this->pdf()])->assertForbidden();

        $this->actingAs($this->investor, 'sanctum')->getJson("/api/marvflow/requests/{$this->req->id}/attachments/{$row->id}")->assertForbidden();
        $this->actingAs($this->investor, 'sanctum')->getJson("/api/admin/dev-requests/{$this->req->id}/attachments/{$row->id}")->assertForbidden();
        $this->actingAs($this->financial, 'sanctum')->getJson("/api/marvflow/requests/{$this->req->id}/attachments/{$row->id}")->assertForbidden();

        $this->assertSame(1, TeamRequestAttachment::count());                // only the admin's own upload exists
    }

    public function test_only_the_requests_sender_or_the_marvflow_team_can_download_an_attachment(): void
    {
        $content = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
        $row = $this->uploadAsAdmin(UploadedFile::fake()->createWithContent('secret.pdf', $content));
        $id = $this->req->id;

        // the sender
        $ok = $this->actingAs($this->admin, 'sanctum')->get("/api/admin/dev-requests/{$id}/attachments/{$row->id}")->assertOk();
        $ok->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment', $ok->headers->get('Content-Disposition'));
        $this->assertSame($content, $ok->streamedContent());

        // the MarvFlow team (existing rule: every member can open every request)
        $this->actingAs($this->member, 'sanctum')->get("/api/marvflow/requests/{$id}/attachments/{$row->id}")->assertOk();

        // another admin and a financial user are NOT the sender → not found, same as the request itself
        $this->actingAs($this->admin2, 'sanctum')->getJson("/api/admin/dev-requests/{$id}/attachments/{$row->id}")->assertNotFound();
        $this->actingAs($this->financial, 'sanctum')->getJson("/api/financial/dev-requests/{$id}/attachments/{$row->id}")->assertNotFound();

        // an attachment of request A can never be fetched through request B's URL
        $other = $this->makeRequest($this->admin2);
        $this->actingAs($this->admin2, 'sanctum')->getJson("/api/admin/dev-requests/{$other->id}/attachments/{$row->id}")->assertNotFound();
        $this->actingAs($this->member, 'sanctum')->getJson("/api/marvflow/requests/{$other->id}/attachments/{$row->id}")->assertNotFound();
    }

    public function test_inline_preview_is_only_ever_granted_to_pdfs(): void
    {
        $pdf = $this->uploadAsAdmin($this->pdf());
        $txt = $this->uploadAsAdmin(UploadedFile::fake()->createWithContent('notes.txt', 'hello'));
        $id = $this->req->id;
        $this->actingAs($this->member, 'sanctum');

        $this->assertStringContainsString('inline', $this->get("/api/marvflow/requests/{$id}/attachments/{$pdf->id}?inline=1")->assertOk()->headers->get('Content-Disposition'));
        $this->assertStringContainsString('attachment', $this->get("/api/marvflow/requests/{$id}/attachments/{$txt->id}?inline=1")->assertOk()->headers->get('Content-Disposition'));
    }

    // ── Storage safety ─────────────────────────────────────────────────────────

    public function test_storage_name_is_server_generated_and_the_display_name_is_sanitised(): void
    {
        $this->send($this->member, $this->replyUrl(), ['body' => 'x', 'attachment' => $this->pdf('evil"<b>.pdf')])->assertOk();
        $row = TeamRequestAttachment::sole();

        $this->assertSame('evilb.pdf', $row->original_name);
        $this->assertMatchesRegularExpression('#^team-requests/\d+/\d{4}/\d{2}/[0-9a-f\-]{36}\.pdf$#', $row->storage_path);
        $this->assertStringNotContainsString('evil', $row->storage_path);
    }

    public function test_a_tampered_storage_path_can_never_be_served(): void
    {
        foreach (['../../.env', '/etc/passwd', 'team-requests/../../.env', 'team-requests/1/2026/01/../../../.env', 'team-requests/1/2026/01/not-a-uuid.pdf'] as $bad) {
            $row = TeamRequestAttachment::create([
                'team_request_id' => $this->req->id, 'uploader_id' => $this->member->id, 'original_name' => 'x.pdf',
                'storage_path' => $bad, 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('a', 64),
            ]);
            $this->actingAs($this->member, 'sanctum')->getJson("/api/marvflow/requests/{$this->req->id}/attachments/{$row->id}")->assertNotFound();
        }
    }

    public function test_a_missing_file_returns_not_found_not_an_error(): void
    {
        $row = $this->uploadAsAdmin($this->pdf());
        Storage::disk('team_documents')->delete($row->storage_path);

        $this->actingAs($this->member, 'sanctum')->getJson("/api/marvflow/requests/{$this->req->id}/attachments/{$row->id}")->assertNotFound();
    }
}
