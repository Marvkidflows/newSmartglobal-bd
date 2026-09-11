<?php
// LOCATION: tests/Feature/AdminTaskTest.php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTaskTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $investor;
    protected TaskType $taskType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->investor = User::factory()->create(['role' => 'investor']);
        $this->taskType = TaskType::create([
            'key' => 'activity', 'label' => 'Activity', 'requires_amount' => true, 'is_active' => true,
        ]);
    }

    public function test_investor_cannot_access_admin_task_endpoints(): void
    {
        $response = $this->actingAs($this->investor, 'sanctum')->getJson('/api/admin/tasks');
        $response->assertStatus(403);
    }

    public function test_guest_cannot_access_admin_task_endpoints(): void
    {
        // Guests fail at the outer auth:sanctum group (401) before ever
        // reaching AdminMiddleware, which returns 403 for authenticated
        // non-admins.
        $response = $this->getJson('/api/admin/tasks');
        $response->assertStatus(401);
    }

    /** Admin provides the task code themselves — nothing is auto-generated. */
    public function test_admin_can_create_task_with_own_code(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/tasks', [
            'task_code'        => 'SSIS6836',
            'user_ids'         => [$this->investor->id],
            'task_type_id'     => $this->taskType->id,
            'title'            => 'Test Signal Task',
            'required_amount'  => 500,
            'expires_at'       => now()->addHours(2)->toDateTimeString(),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('shared_tasks', ['task_code' => 'SSIS6836', 'title' => 'Test Signal Task']);
        $this->assertDatabaseHas('task_assignments', ['user_id' => $this->investor->id, 'status' => 'awaiting_activation']);
    }

    /** Task code is required — no auto-generation fallback. */
    public function test_task_code_is_required(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/tasks', [
            'user_ids'     => [$this->investor->id],
            'task_type_id' => $this->taskType->id,
            'title'        => 'No Code Task',
        ]);

        $response->assertStatus(422);
    }

    /** Task codes must be unique across tasks. */
    public function test_task_codes_are_unique(): void
    {
        $this->createTaskWithAssignment(['task_code' => 'SSIS1234']);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/tasks', [
            'task_code'    => 'SSIS1234',
            'user_ids'     => [$this->investor->id],
            'task_type_id' => $this->taskType->id,
            'title'        => 'Duplicate',
        ]);

        $response->assertStatus(422);
    }

    /** THE core new requirement: one code, assigned to many investors, all
     *  of whom get their own independent tracking record. */
    public function test_admin_can_assign_one_code_to_many_investors(): void
    {
        $investorB = User::factory()->create(['role' => 'investor']);
        $investorC = User::factory()->create(['role' => 'investor']);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/tasks', [
            'task_code'    => 'SHARED001',
            'user_ids'     => [$this->investor->id, $investorB->id, $investorC->id],
            'task_type_id' => $this->taskType->id,
            'title'        => 'Broadcast Task',
        ]);

        $response->assertStatus(201);
        $this->assertEquals(1, Task::where('task_code', 'SHARED001')->count());
        $this->assertEquals(3, TaskAssignment::whereHas('task', fn($q) => $q->where('task_code', 'SHARED001'))->count());
    }

    public function test_cannot_assign_task_to_non_investor(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/tasks', [
            'task_code'    => 'BADASSIGN',
            'user_ids'     => [$this->admin->id],
            'task_type_id' => $this->taskType->id,
            'title'        => 'Bad Task',
        ]);

        $response->assertStatus(422);
    }

    public function test_admin_can_activate_task_for_one_investor(): void
    {
        $assignment = $this->createTaskWithAssignment();

        $response = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/tasks/{$assignment->id}/activate");

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', ['id' => $assignment->id, 'status' => 'active']);
    }

    /** Admin can edit a task's definition before anyone has activated it —
     *  the piece that was missing from the UI entirely. */
    public function test_admin_can_edit_task_before_activation(): void
    {
        $assignment = $this->createTaskWithAssignment(['assignment_status' => 'awaiting_activation']);

        $response = $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/tasks/{$assignment->id}", [
            'title' => 'Updated Title', 'required_amount' => 750,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('shared_tasks', ['id' => $assignment->task_id, 'title' => 'Updated Title', 'required_amount' => 750]);
    }

    /** Editing is blocked once any investor sharing the code has already
     *  progressed — avoids silently rewriting a task people are acting on. */
    public function test_admin_cannot_edit_task_once_an_investor_has_progressed(): void
    {
        $assignment = $this->createTaskWithAssignment(['assignment_status' => 'active']);

        $response = $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/tasks/{$assignment->id}", [
            'title' => 'Should Not Apply',
        ]);

        $response->assertStatus(422);
    }

    /** THE core new requirement: the required amount can vary per
     *  investor, even though they all share the same task code. */
    public function test_admin_can_set_a_different_required_amount_per_investor(): void
    {
        $investorB = User::factory()->create(['role' => 'investor']);
        $task = Task::create([
            'task_code' => 'VARYAMT01', 'task_type_id' => $this->taskType->id,
            'title' => 'Shared Task', 'status' => 'active', 'required_amount' => 500,
        ]);
        $assignmentA = TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->investor->id, 'status' => 'awaiting_activation']);
        $assignmentB = TaskAssignment::create(['task_id' => $task->id, 'user_id' => $investorB->id, 'status' => 'awaiting_activation']);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/tasks/{$assignmentA->id}/amount", [
            'required_amount' => 1000,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', ['id' => $assignmentA->id, 'required_amount' => 1000]);
        // investorB is untouched — still uses the task's shared default.
        $this->assertDatabaseHas('task_assignments', ['id' => $assignmentB->id, 'required_amount' => null]);

        $detailA = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/tasks/{$assignmentA->id}");
        $detailA->assertJsonPath('task.required_amount', 1000);
        $detailA->assertJsonPath('task.has_amount_override', true);

        $detailB = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/tasks/{$assignmentB->id}");
        $detailB->assertJsonPath('task.required_amount', 500);
        $detailB->assertJsonPath('task.has_amount_override', false);
    }

    /** Amount override can be cleared, reverting to the shared default. */
    public function test_admin_can_clear_a_per_investor_amount_override(): void
    {
        $task = Task::create([
            'task_code' => 'CLEARAMT1', 'task_type_id' => $this->taskType->id,
            'title' => 'Shared Task', 'status' => 'active', 'required_amount' => 500,
        ]);
        $assignment = TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->investor->id, 'status' => 'awaiting_activation', 'required_amount' => 1200]);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/tasks/{$assignment->id}/amount", [
            'required_amount' => null,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', ['id' => $assignment->id, 'required_amount' => null]);
    }

    /** Once an investor has activated, their amount is locked in. */
    public function test_amount_override_is_blocked_once_activated(): void
    {
        $assignment = $this->createTaskWithAssignment(['assignment_status' => 'active']);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/tasks/{$assignment->id}/amount", [
            'required_amount' => 999,
        ]);

        $response->assertStatus(422);
    }

    /** Activating one investor's assignment must NOT affect another
     *  investor sharing the same code. */
    public function test_activating_one_investor_does_not_affect_others_sharing_the_code(): void
    {
        $investorB = User::factory()->create(['role' => 'investor']);
        $task = Task::create([
            'task_code' => 'ISOLATE01', 'task_type_id' => $this->taskType->id,
            'title' => 'Isolation Test', 'status' => 'active',
        ]);
        $assignmentA = TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->investor->id, 'status' => 'awaiting_activation']);
        $assignmentB = TaskAssignment::create(['task_id' => $task->id, 'user_id' => $investorB->id, 'status' => 'awaiting_activation']);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/tasks/{$assignmentA->id}/activate");

        $this->assertDatabaseHas('task_assignments', ['id' => $assignmentA->id, 'status' => 'active']);
        $this->assertDatabaseHas('task_assignments', ['id' => $assignmentB->id, 'status' => 'awaiting_activation']);
    }

    public function test_completed_assignment_cannot_be_reactivated(): void
    {
        $assignment = $this->createTaskWithAssignment(['assignment_status' => 'completed']);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/tasks/{$assignment->id}/activate");

        $response->assertStatus(422);
    }

    public function test_expired_task_is_reported_as_expired_on_read(): void
    {
        $assignment = $this->createTaskWithAssignment([
            'assignment_status' => 'active',
            'expires_at'        => now()->subMinute(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/tasks/{$assignment->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('task.status', 'expired');
    }

    /** Extending the window on one investor's row cascades to every
     *  investor sharing that same code. */
    public function test_admin_extending_window_cascades_to_all_shared_assignees(): void
    {
        $investorB = User::factory()->create(['role' => 'investor']);
        $task = Task::create([
            'task_code' => 'CASCADE01', 'task_type_id' => $this->taskType->id,
            'title' => 'Cascade Test', 'status' => 'active', 'expires_at' => now()->addMinutes(10),
        ]);
        $assignmentA = TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->investor->id, 'status' => 'active']);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $investorB->id, 'status' => 'active']);
        $originalExpiry = $task->expires_at;

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/tasks/{$assignmentA->id}/window/extend", ['minutes' => 60]);

        $response->assertStatus(200);
        $task->refresh();
        $this->assertTrue($task->expires_at->gt($originalExpiry));
        // Both investors notified, not just the one whose row was clicked.
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->investor->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $investorB->id]);
    }

    public function test_admin_can_verify_and_complete_task(): void
    {
        $assignment = $this->createTaskWithAssignment(['assignment_status' => 'submitted']);

        $verifyResponse = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/tasks/{$assignment->id}/verify", [
            'amount_used' => 500, 'amount_received' => 635, 'profit_loss' => 135, 'final_result' => 'profit',
        ]);
        $verifyResponse->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', ['id' => $assignment->id, 'status' => 'under_review', 'profit_loss' => 135]);

        $completeResponse = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/tasks/{$assignment->id}/complete");
        $completeResponse->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', ['id' => $assignment->id, 'status' => 'completed']);
    }

    public function test_cannot_verify_task_that_has_not_been_submitted(): void
    {
        $assignment = $this->createTaskWithAssignment(['assignment_status' => 'awaiting_activation']);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/tasks/{$assignment->id}/verify", ['amount_used' => 100]);

        $response->assertStatus(422);
    }

    /** Closing a task for everyone cancels every non-terminal assignee. */
    public function test_admin_can_close_task_for_all_investors(): void
    {
        $investorB = User::factory()->create(['role' => 'investor']);
        $task = Task::create([
            'task_code' => 'CLOSEALL1', 'task_type_id' => $this->taskType->id,
            'title' => 'Close Test', 'status' => 'active',
        ]);
        $assignmentA = TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->investor->id, 'status' => 'awaiting_activation']);
        $assignmentB = TaskAssignment::create(['task_id' => $task->id, 'user_id' => $investorB->id, 'status' => 'completed']);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/tasks/{$assignmentA->id}/close-task");

        $response->assertStatus(200);
        $this->assertDatabaseHas('shared_tasks', ['id' => $task->id, 'status' => 'closed']);
        $this->assertDatabaseHas('task_assignments', ['id' => $assignmentA->id, 'status' => 'cancelled']);
        // Already-completed assignee keeps their own outcome, not overwritten.
        $this->assertDatabaseHas('task_assignments', ['id' => $assignmentB->id, 'status' => 'completed']);
    }

    protected function createTaskWithAssignment(array $overrides = []): TaskAssignment
    {
        $task = Task::create([
            'task_code'    => $overrides['task_code'] ?? ('T' . uniqid()),
            'task_type_id' => $this->taskType->id,
            'title'        => 'Sample Task',
            'status'       => 'active',
            'expires_at'   => $overrides['expires_at'] ?? now()->addHours(2),
        ]);

        return TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $this->investor->id,
            'status'  => $overrides['assignment_status'] ?? 'awaiting_activation',
        ]);
    }
}
