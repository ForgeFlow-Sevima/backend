<?php

use App\Jobs\ExecuteWorkflowRunJob;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookTrigger;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function webhookWorkflow(): Workflow
{
    $tenant = Tenant::query()->create(['name' => 'Webhook Tenant', 'slug' => 'webhook-tenant', 'status' => 'active']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);

    $workflow = Workflow::query()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'name' => 'Webhook Test',
        'description' => 'Incoming webhook workflow',
        'status' => 'active',
    ]);

    $version = $workflow->versions()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'version_number' => 1,
        'definition' => [
            'name' => 'Webhook Test',
            'trigger' => 'webhook',
            'timeoutMs' => 60000,
            'retryPolicy' => ['maxAttempts' => 1, 'backoff' => 'exponential'],
            'steps' => [
                ['id' => 'wait', 'label' => 'Wait', 'type' => 'delay', 'dependsOn' => [], 'config' => ['durationMs' => 1]],
            ],
        ],
        'change_note' => 'Test version',
    ]);

    $workflow->forceFill(['current_version_id' => $version->id])->save();

    return $workflow->refresh();
}

it('creates a webhook-triggered workflow run from a public incoming endpoint', function () {
    Queue::fake();
    $workflow = webhookWorkflow();

    $this->postJson("/api/webhooks/workflows/{$workflow->id}", ['orderId' => 'ord-1'])
        ->assertCreated()
        ->assertJsonPath('data.workflowId', $workflow->id)
        ->assertJsonPath('data.trigger', 'webhook')
        ->assertJsonPath('data.status', 'queued');

    $this->assertDatabaseHas('workflow_runs', [
        'workflow_id' => $workflow->id,
        'trigger_type' => 'webhook',
        'status' => 'pending',
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'tenant_id' => $workflow->tenant_id,
        'action' => 'run.started',
    ]);

    Queue::assertPushed(ExecuteWorkflowRunJob::class);
});

it('rejects non-webhook workflow definitions', function () {
    $workflow = webhookWorkflow();
    $definition = $workflow->currentVersion->definition;
    $definition['trigger'] = 'manual';
    $workflow->currentVersion->forceFill(['definition' => $definition])->save();

    $this->postJson("/api/webhooks/workflows/{$workflow->id}", ['orderId' => 'ord-1'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Workflow is not configured for webhook trigger.');
});

it('validates webhook trigger secret when a trigger exists', function () {
    Queue::fake();
    $workflow = webhookWorkflow();

    WebhookTrigger::query()->create([
        'tenant_id' => $workflow->tenant_id,
        'workflow_id' => $workflow->id,
        'name' => 'Main webhook',
        'secret_hash' => Hash::make('secret-123'),
        'is_active' => true,
    ]);

    $this->postJson("/api/webhooks/workflows/{$workflow->id}", ['orderId' => 'ord-1'])
        ->assertForbidden();

    $this->withHeader('X-FlowForge-Webhook-Secret', 'secret-123')
        ->postJson("/api/webhooks/workflows/{$workflow->id}", ['orderId' => 'ord-1'])
        ->assertCreated()
        ->assertJsonPath('data.trigger', 'webhook');
});
