<?php
// LOCATION: tests/Feature/InvestorTaskTest.php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestorTaskTest extends TestCase
{
    use RefreshDatabase;

    protected User $investorA;
    protected User $investorB;
    protected TaskType $taskType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->investorA = User::factory()->create(['role' => 'investor', 'balance' => 1000]);
        $this->investorB = User::factory()->create(['role' => 'investor', 'balance' => 1000]);
        $this->taskType = TaskType::create([
            'key' => 'signal', 'label' => 'Signal', 'requires_amount' => true, 'is_active' => true,
        ]);
    }

    public function test_invalid_task_code_is_rejected(): void
    {
        $response = $this->actingAs($this->investorA, 'sanctum')
            ->postJson('/api/investor-investment/tasks/submit-code', ['task_code' => 'NOPE0000']);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Invalid task code. Please check the code and try again.');
    }

    /** THE critical security property of the shared-code model: a REAL
     *  code that exists, but that this investor was never assigned to,
     *  must return the exact same generic message as a fake code — no way
     *  to distinguish "wrong code" from "real code, not for you". */
    public function test_valid_code_is_rejected_for_an_unassigned_investor(): void
    {
        $task = $this->createTask(['required_amount' => 500]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->investorB->id, 'status' => 'awaiting_activation']);
        // investorA is deliberately NOT assigned to this task.

        $response = $this->actingAs($this->investorA, 'sanctum')
            ->postJson('/api/investor-investment/tasks/submit-code', ['task_code' => $task->task_code]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Invalid task code. Please check the code and try again.');

        $this->assertDatabaseHas('task_activity_logs', [
            'task_id' => $task->id,
            'action'  => 'code_submission_denied',
        ]);
    }

    /** A code shared with MANY investors works for every one of them,
     *  each getting their own independent activation. */
    public function test_shared_code_works_for_every_assigned_investor_independently(): void
    {
        $investorC = User::factory()->create(['role' => 'investor', 'balance' => 1000]);
        $task = $this->createTask(['required_amount' => 500]);
        $assignmentA = TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->investorA->id, 'status' => 'awaiting_activation']);
        $assignmentB = TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->investorB->id, 'status' => 'awaiting_activation']);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $investorC->id, 'status' => 'awaiting_activation']);

        $responseA = $this->actingAs($this->investorA, 'sanctum')
            ->postJson('/api/investor-investment/tasks/submit-code', ['task_code' => $task->task_code, 'amount' => 500]);
        $responseA->assertStatus(200);
        $responseA->assertJsonPath('message', 'TASK ACTIVATED SUCCESSFULLY');

        $responseB = $this->actingAs($this->investorB, 'sanctum')
            ->postJson('/api/investor-investment/tasks/submit-code', ['task_code' => $task->task_code, 'amount' => 500]);
        $responseB->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', ['id' => $assignmentA->id, 'status' => 'active']);
        $this->assertDatabaseHas('task_assignments', ['id' => $assignmentB->id, 'status' => 'active']);
        // The third investor's assignment is untouched.
        $this->assertDatabaseHas('task_assignments', ['user_id' => $investorC->id, 'status' => 'awaiting_activation']);
    }

    /** Both checks apply together: the typed amount must meet the required
     *  minimum, AND the investor's real balance must cover what they
     *  actually typed. */
    public function test_insufficient_balance_is_rejected(): void
    {
        $poorInvestor = User::factory()->create(['role' => 'investor', 'balance' => 100]);
        $task = $this->createTask(['required_amount' => 500]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $poorInvestor->id, 'status' => 'awaiting_activation']);

        $response = $this->actingAs($poorInvestor, 'sanctum')
            ->postJson('/api/investor-investment/tasks/submit-code', ['task_code' => $task->task_code, 'amount' => 500]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Insufficient balance for this amount. You entered $500.00, your available balance: $100.00.');
        $this->assertDatabaseHas('task_assignments', ['task_id' => $task->id, 'status' => 'awaiting_activation']);
    }

    /** Typed amount must match the required amount exactly — a balance
     *  that's merely "enough" isn't a substitute for entering the right
     *  figure the task actually asks for. */
    /** Amount below the required minimum is still rejected — the message
     *  now reflects a minimum threshold, not an exact match. */
    public function test_amount_below_minimum_is_rejected_even_with_sufficient_balance(): void
    {
        $task = $this->createTask(['required_amount' => 500]); // investorA has balance 1000
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->investorA->id, 'status' => 'awaiting_activation']);

        $response = $this->actingAs($this->investorA, 'sanctum')
            ->postJson('/api/investor-investment/tasks/submit-code', ['task_code' => $task->task_code, 'amount' => 250]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'The amount entered is below what this task requires. Minimum: $500.00.');
    }

    /** THE core new requirement: the investor can commit more than the
     *  minimum — $50, $100, or any amount above it is fine, it doesn't
     *  have to match to the penny — as long as their balance covers it. */
    public function test_amount_above_minimum_is_accepted(): void
    {
        $task = $this->createTask(['required_amount' => 500]); // investorA has balance 1000
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->investorA->id, 'status' => 'awaiting_activation']);

        $response = $this->actingAs($this->investorA, 'sanctum')
            ->postJson('/api/investor-investment/tasks/submit-code', ['task_code' => $task->task_code, 'amount' => 650]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', ['task_id' => $task->id, 'submitted_amount' => 650]);
    }

    /** Balance must cover what they actually TYPE, not just the minimum —
     *  committing more than the minimum still has to be affordable. */
    public function test_amount_above_minimum_but_exceeding_balance_is_rejected(): void
    {
        $investor = User::factory()->create(['role' => 'investor', 'balance' => 600]);
        $task = $this->createTask(['required_amount' => 500]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $investor->id, 'status' => 'awaiting_activation']);

        $response = $this->actingAs($investor, 'sanctum')
            ->postJson('/api/investor-investment/tasks/submit-code', ['task_code' => $task->task_code, 'amount' => 650]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Insufficient balance for this amount. You entered $650.00, your available balance: $600.00.');
    }

    /** Matching amount + sufficient balance together — the happy path. */
    public function test_matching_amount_and_sufficient_balance_allows_activation(): void
    {
        $task = $this->createTask(['required_amount' => 500]); // investorA has balance 1000
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->investorA->id, 'status' => 'awaiting_activation']);

        $response = $this->actingAs($this->investorA, 'sanctum')
            ->postJson('/api/investor-investment/tasks/submit-code', ['task_code' => $task->task_code, 'amount' => 500]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'TASK ACTIVATED SUCCESSFULLY');
    }

    /** A per-investor amount override actually changes what's checked —
     *  the task's shared default no longer applies to that investor. */
    public function test_per_investor_amount_override_is_enforced_instead_of_task_default(): void
    {
        $task = $this->createTask(['required_amount' => 500]); // investorA has balance 1000
        $assignment = TaskAssignment::create([
            'task_id' => $task->id, 'user_id' => $this->investorA->id,
            'status' => 'awaiting_activation', 'required_amount' => 750, // override
        ]);

        // The task's own default (500) is below this investor's override (750).
        $wrongAmount = $this->actingAs($this->investorA, 'sanctum')
            ->postJson('/api/investor-investment/tasks/submit-code', ['task_code' => $task->task_code, 'amount' => 500]);
        $wrongAmount->assertStatus(422);
        $wrongAmount->assertJsonPath('message', 'The amount entered is below what this task requires. Minimum: $750.00.');

        // Their own override (750) is what's actually required.
        $correctAmount = $this->actingAs($this->investorA, 'sanctum')
            ->postJson('/api/investor-investment/tasks/submit-code', ['task_code' => $task->task_code, 'amount' => 750]);
        $correctAmount->assertStatus(200);
    }

    /** THE core new requirement: an investor can enter their code before
     *  the admin's window opens — it's accepted (not rejected), the
     *  assignment stays awaiting_activation but is marked "confirmed", and
     *  the response carries a countdown to start instead of an error. */
    public function test_code_entered_before_window_opens_is_confirmed_not_rejected(): void
    {
        $task = $this->createTask([
            'required_amount' => 500,
            'activates_at'    => now()->addHours(3),
            'expires_at'      => now()->addHours(5),
        ]);
        $assignment = TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->investorA->id, 'status' => 'awaiting_activation']);

        $response = $this->actingAs($this->investorA, 'sanctum')
            ->postJson('/api/investor-investment/tasks/submit-code', ['task_code' => $task->task_code, 'amount' => 500]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Code confirmed! This task will start automatically when the window opens.');
        $response->assertJsonPath('task.code_confirmed', true);
        $response->assertJsonPath('task.status', 'awaiting_activation');

        $this->assertDatabaseHas('task_assignments', ['id' => $assignment->id, 'status' => 'awaiting_activation']);
        $this->assertNotNull($assignment->fresh()->code_confirmed_at);
    }

    /** Once confirmed pre-window, the assignment auto-activates the moment
     *  the window opens — enforced lazily on read, no separate action. */
    public function test_confirmed_assignment_auto_activates_once_window_opens(): void
    {
        $task = $this->createTask([
            'required_amount' => 500,
            'activates_at'    => now()->addMinute(),
            'expires_at'      => now()->addHours(2),
        ]);
        $assignment = TaskAssignment::create([
            'task_id' => $task->id, 'user_id' => $this->investorA->id,
            'status' => 'awaiting_activation', 'code_confirmed_at' => now(),
        ]);

        // Window hasn't opened yet — still shows as awaiting, with a countdown.
        $tooEarly = $this->actingAs($this->investorA, 'sanctum')->getJson("/api/investor-investment/tasks/{$assignment->id}");
        $tooEarly->assertJsonPath('task.status', 'awaiting_activation');
        $this->assertNotNull($tooEarly->json('task.seconds_until_start'));

        // Move time forward past the activation point and read again.
        $task->update(['activates_at' => now()->subSecond()]);

        $afterWindow = $this->actingAs($this->investorA, 'sanctum')->getJson("/api/investor-investment/tasks/{$assignment->id}");
        $afterWindow->assertJsonPath('task.status', 'active');
        $this->assertDatabaseHas('task_assignments', ['id' => $assignment->id, 'status' => 'active']);
    }

    /** Expiry is enforced server-side and applies to every investor sharing
     *  the code — not just whoever happens to read it first. */
    public function test_expired_shared_task_is_rejected_for_all_assignees(): void
    {
        $task = $this->createTask(['required_amount' => 500, 'expires_at' => now()->subMinute()]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->investorA->id, 'status' => 'awaiting_activation']);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->investorB->id, 'status' => 'awaiting_activation']);

        $responseA = $this->actingAs($this->investorA, 'sanctum')
            ->postJson('/api/investor-investment/tasks/submit-code', ['task_code' => $task->task_code]);
        $responseA->assertStatus(422);
        $responseA->assertJsonPath('message', 'This task code has expired.');

        $this->assertDatabaseHas('shared_tasks', ['id' => $task->id, 'status' => 'expired']);

        $responseB = $this->actingAs($this->investorB, 'sanctum')
            ->postJson('/api/investor-investment/tasks/submit-code', ['task_code' => $task->task_code]);
        $responseB->assertStatus(422);
        $responseB->assertJsonPath('message', 'This task code has expired.');
    }

    /** One investor completing/using up their own assignment does not
     *  prevent a different assigned investor from using the same code. */
    public function test_one_investors_completed_assignment_does_not_block_another_assignee(): void
    {
        $task = $this->createTask(['required_amount' => 500]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->investorA->id, 'status' => 'completed']);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->investorB->id, 'status' => 'awaiting_activation']);

        $responseA = $this->actingAs($this->investorA, 'sanctum')
            ->postJson('/api/investor-investment/tasks/submit-code', ['task_code' => $task->task_code]);
        $responseA->assertStatus(422);

        $responseB = $this->actingAs($this->investorB, 'sanctum')
            ->postJson('/api/investor-investment/tasks/submit-code', ['task_code' => $task->task_code, 'amount' => 500]);
        $responseB->assertStatus(200);
    }

    public function test_duplicate_activity_submission_is_rejected(): void
    {
        $assignment = $this->createAssignment($this->investorA, ['assignment_status' => 'submitted']);

        $response = $this->actingAs($this->investorA, 'sanctum')
            ->postJson("/api/investor-investment/tasks/{$assignment->id}/submit", ['notes' => 'again']);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'This task has already been submitted and is awaiting review.');
    }

    public function test_investor_cannot_view_another_investors_assignment(): void
    {
        $assignment = $this->createAssignment($this->investorB);

        $response = $this->actingAs($this->investorA, 'sanctum')
            ->getJson("/api/investor-investment/tasks/{$assignment->id}");

        $response->assertStatus(404);
    }

    public function test_investor_can_submit_activity_on_active_task(): void
    {
        $assignment = $this->createAssignment($this->investorA, ['assignment_status' => 'active']);

        $response = $this->actingAs($this->investorA, 'sanctum')
            ->postJson("/api/investor-investment/tasks/{$assignment->id}/submit", ['notes' => 'Completed the signal trade.']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', ['id' => $assignment->id, 'status' => 'submitted']);
    }

    public function test_guest_cannot_access_investor_task_endpoints(): void
    {
        $response = $this->getJson('/api/investor-investment/tasks');
        $response->assertStatus(401);
    }

    protected function createTask(array $overrides = []): Task
    {
        return Task::create(array_merge([
            'task_code'    => 'T' . uniqid(),
            'task_type_id' => $this->taskType->id,
            'title'        => 'Sample Task',
            'status'       => 'active',
            'expires_at'   => now()->addHours(2),
        ], $overrides));
    }

    protected function createAssignment(User $owner, array $overrides = []): TaskAssignment
    {
        $task = $this->createTask($overrides);
        return TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $owner->id,
            'status'  => $overrides['assignment_status'] ?? 'awaiting_activation',
        ]);
    }
}
