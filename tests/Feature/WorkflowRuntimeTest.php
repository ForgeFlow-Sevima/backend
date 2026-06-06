<?php

use App\Jobs\ExecuteWorkflowStepJob;
use App\Models\ScheduledTrigger;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Workflow\ScheduledTriggerService;
use App\Services\Workflow\WorkflowContextResolver;
use App\Services\Workflow\WorkflowExecutionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function runtimeWorkflow(array $definition): array
{
    $tenant = Tenant::query()->create(['name' => 'Runtime Tenant', 'slug' => uniqid('runtime-'), 'status' => 'active']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
    $workflow = Workflow::query()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'name' => $definition['name'],
        'description' => 'Runtime test',
        'status' => 'active',
    ]);
    $version = $workflow->versions()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'version_number' => 1,
        'definition' => $definition,
        'change_note' => 'Runtime test',
    ]);
    $workflow->forceFill(['current_version_id' => $version->id])->save();

    return [$workflow->refresh(), $user];
}

it('resolves dynamic binding templates from workflow context', function () {
    $resolver = new WorkflowContextResolver;

    expect($resolver->resolveValue(['id' => '{{ steps.get-order.body.data.id }}'], [
        'steps' => ['get-order' => ['body' => ['data' => ['id' => 'ord-1']]]],
    ]))->toBe(['id' => 'ord-1']);
});

it('dispatches independent root steps as parallel step jobs', function () {
    Queue::fake();
    [$workflow, $user] = runtimeWorkflow([
        'name' => 'Parallel Test',
        'trigger' => 'manual',
        'timeoutMs' => 60000,
        'retryPolicy' => ['maxAttempts' => 1, 'backoff' => 'exponential'],
        'steps' => [
            ['id' => 'a', 'label' => 'A', 'type' => 'delay', 'dependsOn' => [], 'config' => ['durationMs' => 1]],
            ['id' => 'b', 'label' => 'B', 'type' => 'delay', 'dependsOn' => [], 'config' => ['durationMs' => 1]],
        ],
    ]);

    $service = app(WorkflowExecutionService::class);
    $run = $service->createRun($workflow, $user->id);
    $service->execute($run);

    Queue::assertPushed(ExecuteWorkflowStepJob::class, 2);
});

it('skips the unselected condition branch', function () {
    Queue::fake();
    [$workflow, $user] = runtimeWorkflow([
        'name' => 'Branch Test',
        'trigger' => 'manual',
        'timeoutMs' => 60000,
        'retryPolicy' => ['maxAttempts' => 1, 'backoff' => 'exponential'],
        'steps' => [
            ['id' => 'check', 'label' => 'Check', 'type' => 'condition', 'dependsOn' => [], 'config' => ['left' => 'paid', 'operator' => 'equals', 'right' => 'paid', 'onTrue' => ['true-step'], 'onFalse' => ['false-step']]],
            ['id' => 'true-step', 'label' => 'True', 'type' => 'delay', 'dependsOn' => ['check'], 'config' => ['durationMs' => 1]],
            ['id' => 'false-step', 'label' => 'False', 'type' => 'delay', 'dependsOn' => ['check'], 'config' => ['durationMs' => 1]],
        ],
    ]);

    $service = app(WorkflowExecutionService::class);
    $run = $service->createRun($workflow, $user->id);
    $service->executeSingleStep($run->id, 'check');
    $service->execute($run->fresh(['workflow.currentVersion', 'stepRuns']));

    expect($run->fresh()->stepRuns()->where('step_id', 'false-step')->first()->status)->toBe('skipped');
});

it('pauses on approval step and creates an approval record', function () {
    [$workflow, $user] = runtimeWorkflow([
        'name' => 'Approval Test',
        'trigger' => 'manual',
        'timeoutMs' => 60000,
        'retryPolicy' => ['maxAttempts' => 1, 'backoff' => 'exponential'],
        'steps' => [
            ['id' => 'approve', 'label' => 'Approve', 'type' => 'approval', 'dependsOn' => [], 'config' => ['title' => 'Approve run', 'approvers' => ['admin']]],
        ],
    ]);

    $service = app(WorkflowExecutionService::class);
    $run = $service->createRun($workflow, $user->id);
    $service->executeSingleStep($run->id, 'approve');

    expect($run->fresh()->status)->toBe('waiting_approval')
        ->and($run->fresh()->approvals()->count())->toBe(1);
});

it('creates scheduled runs for due scheduled triggers', function () {
    Queue::fake();
    [$workflow, $user] = runtimeWorkflow([
        'name' => 'Scheduled Test',
        'trigger' => 'scheduled',
        'timeoutMs' => 60000,
        'retryPolicy' => ['maxAttempts' => 1, 'backoff' => 'exponential'],
        'steps' => [
            ['id' => 'wait', 'label' => 'Wait', 'type' => 'delay', 'dependsOn' => [], 'config' => ['durationMs' => 1]],
        ],
    ]);

    ScheduledTrigger::query()->create([
        'tenant_id' => $workflow->tenant_id,
        'workflow_id' => $workflow->id,
        'created_by' => $user->id,
        'name' => 'Every minute',
        'cron_expression' => '* * * * *',
        'timezone' => 'Asia/Jakarta',
        'is_active' => true,
        'next_run_at' => now()->subMinute(),
    ]);

    $runs = app(ScheduledTriggerService::class)->runDue();

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->trigger_type)->toBe('scheduled');
});

it('does not create scheduled runs for inactive triggers', function () {
    Queue::fake();
    [$workflow, $user] = runtimeWorkflow([
        'name' => 'Paused Scheduled Test',
        'trigger' => 'scheduled',
        'timeoutMs' => 60000,
        'retryPolicy' => ['maxAttempts' => 1, 'backoff' => 'exponential'],
        'steps' => [
            ['id' => 'wait', 'label' => 'Wait', 'type' => 'delay', 'dependsOn' => [], 'config' => ['durationMs' => 1]],
        ],
    ]);

    ScheduledTrigger::query()->create([
        'tenant_id' => $workflow->tenant_id,
        'workflow_id' => $workflow->id,
        'created_by' => $user->id,
        'name' => 'Paused every minute',
        'cron_expression' => '* * * * *',
        'timezone' => 'Asia/Jakarta',
        'is_active' => false,
        'next_run_at' => now()->subMinute(),
    ]);

    expect(app(ScheduledTriggerService::class)->runDue())->toHaveCount(0);
});

it('calculates next scheduled run in trigger timezone without shifting to previous day', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-07 03:19:30', 'Asia/Jakarta'));

    try {
        $service = app(ScheduledTriggerService::class);

        expect($service->nextRunAt('* * * * *', 'Asia/Jakarta')->toIso8601String())
            ->toBe('2026-06-07T03:20:00+07:00')
            ->and($service->nextRunAt('*/5 * * * *', 'Asia/Jakarta')->toIso8601String())
            ->toBe('2026-06-07T03:20:00+07:00')
            ->and($service->nextRunAt('20 3 * * *', 'Asia/Jakarta')->toIso8601String())
            ->toBe('2026-06-07T03:20:00+07:00');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('moves fixed daily cron to tomorrow when todays time has passed', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-07 03:21:00', 'Asia/Jakarta'));

    try {
        expect(app(ScheduledTriggerService::class)->nextRunAt('20 3 * * *', 'Asia/Jakarta')->toIso8601String())
            ->toBe('2026-06-08T03:20:00+07:00');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('rejects invalid scheduled trigger cron expression and timezone', function () {
    [$workflow, $user] = runtimeWorkflow([
        'name' => 'Scheduled Validation Test',
        'trigger' => 'scheduled',
        'timeoutMs' => 60000,
        'retryPolicy' => ['maxAttempts' => 1, 'backoff' => 'exponential'],
        'steps' => [
            ['id' => 'wait', 'label' => 'Wait', 'type' => 'delay', 'dependsOn' => [], 'config' => ['durationMs' => 1]],
        ],
    ]);
    $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->json('data.token');

    $this->withToken($token)
        ->postJson('/api/v1/scheduled-triggers', [
            'workflowId' => $workflow->id,
            'name' => 'Invalid schedule',
            'cronExpression' => 'bad cron',
            'timezone' => 'Asia/Jakarta',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cronExpression']);

    $this->withToken($token)
        ->postJson('/api/v1/scheduled-triggers', [
            'workflowId' => $workflow->id,
            'name' => 'Invalid timezone',
            'cronExpression' => '* * * * *',
            'timezone' => 'Asia/Jakartaaa',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['timezone']);
});

it('lists pauses and resumes scheduled triggers for a workflow', function () {
    [$workflow, $user] = runtimeWorkflow([
        'name' => 'Scheduled Control Test',
        'trigger' => 'scheduled',
        'timeoutMs' => 60000,
        'retryPolicy' => ['maxAttempts' => 1, 'backoff' => 'exponential'],
        'steps' => [
            ['id' => 'wait', 'label' => 'Wait', 'type' => 'delay', 'dependsOn' => [], 'config' => ['durationMs' => 1]],
        ],
    ]);
    $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->json('data.token');

    $triggerId = $this->withToken($token)
        ->postJson('/api/v1/scheduled-triggers', [
            'workflowId' => $workflow->id,
            'name' => 'Every minute',
            'cronExpression' => '* * * * *',
            'timezone' => 'Asia/Jakarta',
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken($token)
        ->getJson("/api/v1/workflows/{$workflow->id}/scheduled-triggers")
        ->assertOk()
        ->assertJsonPath('data.0.id', $triggerId);

    $this->withToken($token)
        ->patchJson("/api/v1/scheduled-triggers/{$triggerId}", ['isActive' => false])
        ->assertOk()
        ->assertJsonPath('data.isActive', false)
        ->assertJsonPath('data.nextRunAt', null);

    $resumeResponse = $this->withToken($token)
        ->patchJson("/api/v1/scheduled-triggers/{$triggerId}", ['isActive' => true])
        ->assertOk()
        ->assertJsonPath('data.isActive', true);

    expect($resumeResponse->json('data.nextRunAt'))->not->toBeNull()
        ->and(ScheduledTrigger::query()->findOrFail($triggerId)->next_run_at)->not->toBeNull();
});
