<?php

use App\Jobs\ExecuteWorkflowRunJob;
use App\Models\ExecutionLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.url', 'http://127.0.0.1:8000');
});

function apiTokenForAdmin(): array
{
    $tenant = Tenant::query()->create(['name' => 'API Tenant', 'slug' => uniqid('api-'), 'status' => 'active']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin', 'email' => uniqid('admin-').'@example.test']);

    $token = test()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk()->json('data.token');

    return [$token, $user, $tenant];
}

function workflowPayload(array $definition): array
{
    return [
        'name' => $definition['name'],
        'description' => 'API integration workflow',
        'status' => 'active',
        'changeNote' => 'API integration test',
        'definition' => $definition,
    ];
}

it('validates workflow definitions through the API', function () {
    [$token] = apiTokenForAdmin();

    $this->withToken($token)
        ->postJson('/api/v1/workflows/validate', ['definition' => testWorkflowDefinition()])
        ->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.topologicalOrder.0', 'fetch-order');

    $invalid = testWorkflowDefinition([
        'steps' => [
            ['id' => 'a', 'label' => 'A', 'type' => 'delay', 'dependsOn' => ['b'], 'config' => ['durationMs' => 1]],
            ['id' => 'b', 'label' => 'B', 'type' => 'delay', 'dependsOn' => ['a'], 'config' => ['durationMs' => 1]],
        ],
    ]);

    $this->withToken($token)
        ->postJson('/api/v1/workflows/validate', ['definition' => $invalid])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['definition.steps']);
});

it('creates workflows with an active version and audit log through the API', function () {
    [$token, $user] = apiTokenForAdmin();

    $response = $this->withToken($token)
        ->postJson('/api/v1/workflows', workflowPayload(testWorkflowDefinition()))
        ->assertCreated();

    $workflowId = $response->json('data.id');

    $workflow = Workflow::query()->with('currentVersion')->findOrFail($workflowId);

    expect($workflow->tenant_id)->toBe($user->tenant_id)
        ->and($workflow->currentVersion)->not->toBeNull()
        ->and($workflow->currentVersion->definition['name'])->toBe('Test Workflow');

    $this->assertDatabaseHas('audit_logs', [
        'tenant_id' => $user->tenant_id,
        'action' => 'workflow.created',
    ]);
});

it('starts workflow runs through the API and records run audit data', function () {
    Queue::fake();
    [$token, $user] = apiTokenForAdmin();
    $workflow = createRuntimeWorkflowForUser($user, testWorkflowDefinition());

    $response = $this->withToken($token)
        ->postJson("/api/v1/workflows/{$workflow->id}/runs", ['input' => ['body' => ['orderId' => '1006']]])
        ->assertCreated()
        ->assertJsonPath('data.workflowId', $workflow->id)
        ->assertJsonPath('data.trigger', 'manual');

    $runId = $response->json('data.id');

    $this->assertDatabaseHas('workflow_runs', [
        'id' => $runId,
        'tenant_id' => $user->tenant_id,
        'workflow_id' => $workflow->id,
        'status' => 'pending',
    ]);

    $this->assertDatabaseCount('step_runs', 4);
    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $user->tenant_id, 'action' => 'run.started']);
    Queue::assertPushed(ExecuteWorkflowRunJob::class);
});

it('paginates workflow, run, and log API lists with custom perPage values', function () {
    [$token, $user] = apiTokenForAdmin();
    $workflow = createRuntimeWorkflowForUser($user, testWorkflowDefinition());

    foreach (range(1, 3) as $index) {
        $run = $workflow->runs()->create([
            'tenant_id' => $user->tenant_id,
            'workflow_version_id' => $workflow->current_version_id,
            'triggered_by_user_id' => $user->id,
            'status' => 'success',
            'trigger_type' => 'manual',
        ]);

        ExecutionLog::query()->create([
            'tenant_id' => $user->tenant_id,
            'workflow_run_id' => $run->id,
            'level' => 'info',
            'message' => "Log $index",
        ]);
    }

    $this->withToken($token)
        ->getJson('/api/v1/workflows?perPage=1&page=1')
        ->assertOk()
        ->assertJsonPath('meta.perPage', 1)
        ->assertJsonPath('meta.total', 1);

    $this->withToken($token)
        ->getJson('/api/v1/runs?perPage=2&page=1')
        ->assertOk()
        ->assertJsonPath('meta.perPage', 2)
        ->assertJsonPath('meta.total', 3);

    $this->withToken($token)
        ->getJson('/api/v1/logs?perPage=2&page=1')
        ->assertOk()
        ->assertJsonPath('meta.perPage', 2)
        ->assertJsonPath('meta.total', 3);
});
