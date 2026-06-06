<?php

use App\Jobs\ExecuteWorkflowStepJob;
use App\Services\Workflow\WorkflowExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.url', 'http://127.0.0.1:8000');
});

it('creates a run with step runs and an initial log', function () {
    [$workflow, $user] = createRuntimeWorkflow(testWorkflowDefinition());

    $run = app(WorkflowExecutionService::class)->createRun($workflow, $user->id, ['body' => ['orderId' => '1006']]);

    expect($run->status)->toBe('pending')
        ->and($run->stepRuns)->toHaveCount(4)
        ->and($run->executionLogs)->toHaveCount(1)
        ->and($run->executionLogs->first()->message)->toBe('Workflow run queued.');
});

it('dispatches independent root steps as parallel jobs', function () {
    Queue::fake();
    [$workflow, $user] = createRuntimeWorkflow(testWorkflowDefinition([
        'steps' => [
            ['id' => 'a', 'label' => 'A', 'type' => 'delay', 'dependsOn' => [], 'config' => ['durationMs' => 1]],
            ['id' => 'b', 'label' => 'B', 'type' => 'delay', 'dependsOn' => [], 'config' => ['durationMs' => 1]],
        ],
    ]));

    $service = app(WorkflowExecutionService::class);
    $run = $service->createRun($workflow, $user->id);

    $service->execute($run);

    Queue::assertPushed(ExecuteWorkflowStepJob::class, 2);
});

it('skips the unselected branch after a condition step succeeds', function () {
    Queue::fake();
    [$workflow, $user] = createRuntimeWorkflow(testWorkflowDefinition([
        'steps' => [
            ['id' => 'check', 'label' => 'Check', 'type' => 'condition', 'dependsOn' => [], 'config' => ['left' => 'paid', 'operator' => 'equals', 'right' => 'paid', 'onTrue' => ['true-step'], 'onFalse' => ['false-step']]],
            ['id' => 'true-step', 'label' => 'True', 'type' => 'delay', 'dependsOn' => ['check'], 'config' => ['durationMs' => 1]],
            ['id' => 'false-step', 'label' => 'False', 'type' => 'delay', 'dependsOn' => ['check'], 'config' => ['durationMs' => 1]],
        ],
    ]));

    $service = app(WorkflowExecutionService::class);
    $run = $service->createRun($workflow, $user->id);

    $service->executeSingleStep($run->id, 'check');
    $service->execute($run->fresh(['workflow.currentVersion', 'workflowVersion', 'stepRuns']));

    expect($run->fresh()->stepRuns()->where('step_id', 'false-step')->first()->status)->toBe('skipped');
});

it('executes http and script steps and stores their outputs', function () {
    [$workflow, $user] = createRuntimeWorkflow(testWorkflowDefinition([
        'steps' => [
            [
                'id' => 'fetch-order',
                'label' => 'Fetch order',
                'type' => 'http',
                'dependsOn' => [],
                'config' => [
                    'method' => 'GET',
                    'url' => config('app.url').'/api/mock/orders/{{ input.body.orderId }}',
                    'headers' => [],
                    'body' => null,
                    'timeoutMs' => 10000,
                ],
            ],
            [
                'id' => 'summarize',
                'label' => 'Summarize',
                'type' => 'script',
                'dependsOn' => ['fetch-order'],
                'config' => ['functionName' => 'countPreviousOutputs', 'input' => []],
            ],
        ],
    ]));

    $service = app(WorkflowExecutionService::class);
    $run = $service->createRun($workflow, $user->id, ['body' => ['orderId' => '1006']]);

    $service->executeSingleStep($run->id, 'fetch-order');
    $service->executeSingleStep($run->id, 'summarize');

    $fetch = $run->fresh()->stepRuns()->where('step_id', 'fetch-order')->first();
    $summary = $run->fresh()->stepRuns()->where('step_id', 'summarize')->first();

    expect($fetch->status)->toBe('success')
        ->and($fetch->output_payload['body']['data']['id'])->toBe('1006')
        ->and($summary->status)->toBe('success')
        ->and($summary->output_payload['count'])->toBe(1);
});

it('pauses on an approval step and creates an approval record', function () {
    [$workflow, $user] = createRuntimeWorkflow(testWorkflowDefinition([
        'steps' => [
            ['id' => 'approve', 'label' => 'Approve', 'type' => 'approval', 'dependsOn' => [], 'config' => ['title' => 'Approve test run', 'approvers' => ['admin']]],
        ],
    ]));

    $service = app(WorkflowExecutionService::class);
    $run = $service->createRun($workflow, $user->id);

    $service->executeSingleStep($run->id, 'approve');

    expect($run->fresh()->status)->toBe('waiting_approval')
        ->and($run->fresh()->approvals()->count())->toBe(1)
        ->and($run->fresh()->stepRuns()->first()->status)->toBe('waiting_approval');
});
