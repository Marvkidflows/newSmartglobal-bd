<?php
// LOCATION: tests/Feature/RichTextMigrationsTest.php
//
// The two rich-text migrations are additive and reversible: nullable columns, nothing rewritten, a guarded rollback for staff subjects.

namespace Tests\Feature;

use App\Models\Message;
use App\Models\StaffMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RichTextMigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_columns_exist_and_existing_style_rows_are_untouched(): void
    {
        $this->assertTrue(Schema::hasColumns('messages', ['body', 'body_html']));
        $this->assertTrue(Schema::hasColumns('staff_messages', ['body', 'subject', 'body_html']));

        $admin = User::factory()->create(['role' => 'admin']);
        $old   = StaffMessage::create(['sender_id' => $admin->id, 'body' => 'before the upgrade']);                 // the legacy shape
        $this->assertNull($old->fresh()->subject);
        $this->assertNull($old->fresh()->body_html);
        $this->assertSame('before the upgrade', $old->fresh()->body);

        $inv = User::factory()->create(['role' => 'investor']);
        $m   = Message::create(['investor_id' => $inv->id, 'sender_id' => $admin->id, 'receiver_id' => $inv->id, 'body' => 'legacy', 'initiated_by' => 'admin', 'read_by_admin' => true, 'read_by_investor' => false, 'department' => 'admin']);
        $this->assertNull($m->fresh()->body_html);
    }

    public function test_the_staff_subject_rollback_refuses_while_subjects_exist_and_changes_nothing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        StaffMessage::create(['sender_id' => $admin->id, 'subject' => 'Keep me', 'body' => 'x']);

        $migration = require base_path('database/migrations/2026_10_31_100002_add_subject_and_rich_text_to_staff_messages_table.php');
        try {
            $migration->down();
            $this->fail('down() must refuse while subjects exist.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Rollback refused', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumns('staff_messages', ['subject', 'body_html']));
        $this->assertSame('Keep me', StaffMessage::firstOrFail()->subject);
    }

    public function test_the_messages_rollback_is_lossless_for_text(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $inv   = User::factory()->create(['role' => 'investor']);
        Message::create(['investor_id' => $inv->id, 'sender_id' => $admin->id, 'receiver_id' => $inv->id, 'body' => 'plain text survives', 'body_html' => '<p><strong>plain</strong> text survives</p>',
                         'initiated_by' => 'admin', 'read_by_admin' => true, 'read_by_investor' => false, 'department' => 'admin']);

        $migration = require base_path('database/migrations/2026_10_31_100001_add_rich_text_to_messages_table.php');
        $migration->down();

        $this->assertFalse(Schema::hasColumn('messages', 'body_html'));
        $this->assertSame('plain text survives', Message::firstOrFail()->body);               // only the formatting is gone
    }
}
