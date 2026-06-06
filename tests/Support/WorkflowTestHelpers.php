<?php

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;

function testWorkflowDefinition(array $overrides = []): array
{
    $definition = [
        'name' => 'Test Workflow',
        'trigger' => 'manual',
        'timeoutMs' => 60000,
        'retryPolicy' => ['maxAttempts' => 1, 'backoff' => 'exponential'],
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
                'id' => 'check-total',
                'label' => 'Check total',
                'type' => 'condition',
                'dependsOn' => ['fetch-order'],
                'config' => [
                    'left' => '{{ steps.fetch-order.body.data.status }}',
                    'operator' => 'equals',
                    'right' => 'paid',
                    'onTrue' => ['notify-customer'],
                    'onFalse' => ['summarize'],
                ],
            ],
            [
                'id' => 'notify-customer',
                'label' => 'Notify customer',
                'type' => 'http',
                'dependsOn' => ['check-total'],
                'config' => [
                    'method' => 'POST',
                    'url' => config('app.url').'/api/mock/notifications',
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => [
                        'channel' => 'email',
                        'recipient' => '{{ steps.fetch-order.body.data.customer.email }}',
                        'message' => 'Order {{ steps.fetch-order.body.data.id }} is paid.',
                        'metadata' => ['source' => 'test'],
                    ],
                    'timeoutMs' => 10000,
                ],
            ],
            [
                'id' => 'summarize',
                'label' => 'Summarize',
                'type' => 'script',
                'dependsOn' => ['notify-customer'],
                'config' => [
                    'functionName' => 'countPreviousOutputs',
                    'input' => [],
                ],
            ],
        ],
    ];

    foreach ($overrides as $key => $value) {
        $definition[$key] = is_array($value) && $key !== 'steps'
            ? array_replace_recursive($definition[$key] ?? [], $value)
            : $value;
    }

    return $definition;
}

function createRuntimeWorkflow(array $definition, array $workflowAttributes = []): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Test Tenant',
        'slug' => uniqid('tenant-'),
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => 'admin',
    ]);

    $workflow = Workflow::query()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'name' => $definition['name'],
        'description' => 'Test workflow',
        'status' => 'active',
        ...$workflowAttributes,
    ]);

    $version = $workflow->versions()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'version_number' => 1,
        'definition' => $definition,
        'change_note' => 'Test version',
    ]);

    $workflow->forceFill(['current_version_id' => $version->id])->save();

    return [$workflow->refresh(), $user, $tenant];
}

function createRuntimeWorkflowForUser(User $user, array $definition, array $workflowAttributes = []): Workflow
{
    $workflow = Workflow::query()->create([
        'tenant_id' => $user->tenant_id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'name' => $definition['name'],
        'description' => 'Test workflow',
        'status' => 'active',
        ...$workflowAttributes,
    ]);

    $version = $workflow->versions()->create([
        'tenant_id' => $user->tenant_id,
        'created_by' => $user->id,
        'version_number' => 1,
        'definition' => $definition,
        'change_note' => 'Test version',
    ]);

    $workflow->forceFill(['current_version_id' => $version->id])->save();

    return $workflow->refresh();
}
