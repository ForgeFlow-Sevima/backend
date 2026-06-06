<?php

use App\Jobs\ExecuteWorkflowStepJob;
use App\Models\ScheduledTrigger;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Workflow\ScheduledTriggerService;
use App\Services\Workflow\WorkflowContextResolver;
use App\Services\Workflow\WorkflowExecutionService;
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
    $resolver = new WorkflowContextResolver();

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
