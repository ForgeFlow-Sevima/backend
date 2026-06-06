<?php

namespace Database\Seeders;

use App\Models\AiFailureAnalysis;
use App\Models\ExecutionLog;
use App\Models\StepRun;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowVersion;
use App\Support\MockApiUrls;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => 'flowforge-demo'],
            ['name' => 'FlowForge Demo', 'status' => 'active'],
        );

        $admin = $this->user($tenant, 'Alya Putri', 'admin@flowforge.test', 'password', 'admin');
        $this->user($tenant, 'Bima Hartono', 'editor@flowforge.test', 'password', 'editor');
        $this->user($tenant, 'Citra Lestari', 'viewer@flowforge.test', 'password', 'viewer');

        $orderWorkflow = $this->workflow($tenant, $admin, [
            'id' => '11111111-1111-4111-8111-111111111111',
            'name' => 'Order Sync Pipeline',
            'description' => 'Validate paid orders, call warehouse API, and notify the fulfillment team.',
            'status' => 'active',
            'last_run_at' => now()->subMinutes(18),
            'version' => 12,
            'definition' => [
                'name' => 'Order Sync Pipeline',
                'trigger' => 'webhook',
                'timeoutMs' => 60000,
                'retryPolicy' => ['maxAttempts' => 3, 'backoff' => 'exponential'],
                'steps' => [
                    ['id' => 'receive-order', 'label' => 'Receive order webhook', 'type' => 'http', 'dependsOn' => [], 'config' => ['url' => MockApiUrls::order('2')]],
                    ['id' => 'reserve-stock', 'label' => 'Reserve stock', 'type' => 'script', 'dependsOn' => ['receive-order'], 'config' => ['functionName' => 'echoPayload']],
                ],
            ],
        ]);

        $reportWorkflow = $this->workflow($tenant, $admin, [
            'id' => '22222222-2222-4222-8222-222222222222',
            'name' => 'Daily Revenue Report',
            'description' => 'Collect marketplace revenue and send the daily digest.',
            'status' => 'active',
            'last_run_at' => now()->subMinutes(45),
            'version' => 7,
            'definition' => [
                'name' => 'Daily Revenue Report',
                'trigger' => 'scheduled',
                'timeoutMs' => 120000,
                'retryPolicy' => ['maxAttempts' => 3, 'backoff' => 'exponential'],
                'steps' => [
                    ['id' => 'fetch-orders', 'label' => 'Fetch orders', 'type' => 'http', 'dependsOn' => [], 'config' => ['url' => MockApiUrls::order('2')]],
                    ['id' => 'wait-settlement', 'label' => 'Wait settlement', 'type' => 'delay', 'dependsOn' => ['fetch-orders'], 'config' => ['durationMs' => 1000]],
                ],
            ],
        ]);

        $legacyWorkflow = $this->workflow($tenant, $admin, [
            'id' => '33333333-3333-4333-8333-333333333333',
            'name' => 'Legacy CRM Webhook',
            'description' => 'Archived webhook workflow kept for run history and audit review.',
            'status' => 'archived',
            'last_run_at' => now()->subDays(1),
            'version' => 4,
            'definition' => [
                'name' => 'Legacy CRM Webhook',
                'trigger' => 'webhook',
                'timeoutMs' => 60000,
                'retryPolicy' => ['maxAttempts' => 2, 'backoff' => 'exponential'],
                'steps' => [
                    ['id' => 'receive-crm', 'label' => 'Receive CRM event', 'type' => 'http', 'dependsOn' => [], 'config' => ['url' => MockApiUrls::order('1')]],
                    ['id' => 'map-fields', 'label' => 'Map fields', 'type' => 'script', 'dependsOn' => ['receive-crm'], 'config' => ['functionName' => 'echoPayload']],
                ],
            ],
        ]);

        $this->runRecord($tenant, $admin, $orderWorkflow, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa1042', 'success', 'webhook', 17200, null, now()->subMinutes(18));
        $this->runRecord($tenant, $admin, $orderWorkflow, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa1041', 'failed', 'webhook', 22900, 'reserve-stock', now()->subMinutes(38));
        $this->runRecord($tenant, $admin, $reportWorkflow, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbb1040', 'running', 'scheduled', 31100, null, now()->subMinutes(45));
        $this->runRecord($tenant, $admin, $legacyWorkflow, 'cccccccc-cccc-4ccc-8ccc-cccccccc1037', 'failed', 'webhook', 49200, 'map-fields', now()->subHours(7));

        // $orderWorkflow = $this->workflow($tenant, $admin, [
        //     'id' => '11111111-1111-4111-8111-111111111111',
        //     'name' => 'Order Sync Pipeline',
        //     'description' => 'Validate paid orders, call warehouse API, and notify the fulfillment team.',
        //     'status' => 'active',
        //     'last_run_at' => now()->subMinutes(18),
        //     'version' => 12,
        //     'definition' => [
        //         'name' => 'Order Sync Pipeline',
        //         'trigger' => 'webhook',
        //         'timeoutMs' => 60000,
        //         'retryPolicy' => ['maxAttempts' => 3, 'backoff' => 'exponential'],
        //         'steps' => [
        //             ['id' => 'receive-order', 'label' => 'Receive order webhook', 'type' => 'http', 'dependsOn' => [], 'status' => 'success'],
        //             ['id' => 'validate-stock', 'label' => 'Validate stock', 'type' => 'http', 'dependsOn' => ['receive-order'], 'status' => 'success'],
        //             ['id' => 'reserve-stock', 'label' => 'Reserve stock', 'type' => 'script', 'dependsOn' => ['validate-stock'], 'status' => 'success'],
        //             ['id' => 'notify-ops', 'label' => 'Notify operations', 'type' => 'http', 'dependsOn' => ['reserve-stock'], 'status' => 'success'],
        //         ],
        //     ],
        // ]);

        // $reportWorkflow = $this->workflow($tenant, $admin, [
        //     'id' => '22222222-2222-4222-8222-222222222222',
        //     'name' => 'Daily Revenue Report',
        //     'description' => 'Collect marketplace revenue, wait for settlement, and send the daily digest.',
        //     'status' => 'active',
        //     'last_run_at' => now()->subMinutes(45),
        //     'version' => 7,
        //     'definition' => [
        //         'name' => 'Daily Revenue Report',
        //         'trigger' => 'scheduled',
        //         'timeoutMs' => 120000,
        //         'retryPolicy' => ['maxAttempts' => 3, 'backoff' => 'exponential'],
        //         'steps' => [
        //             ['id' => 'fetch-orders', 'label' => 'Fetch orders', 'type' => 'http', 'dependsOn' => [], 'status' => 'success'],
        //             ['id' => 'wait-settlement', 'label' => 'Wait settlement', 'type' => 'delay', 'dependsOn' => ['fetch-orders'], 'status' => 'running'],
        //             ['id' => 'branch-threshold', 'label' => 'Check threshold', 'type' => 'condition', 'dependsOn' => ['wait-settlement']],
        //             ['id' => 'send-report', 'label' => 'Send report', 'type' => 'http', 'dependsOn' => ['branch-threshold']],
        //         ],
        //     ],
        // ]);

        // $legacyWorkflow = $this->workflow($tenant, $admin, [
        //     'id' => '33333333-3333-4333-8333-333333333333',
        //     'name' => 'Legacy CRM Webhook',
        //     'description' => 'Archived webhook workflow kept for run history and audit review.',
        //     'status' => 'archived',
        //     'last_run_at' => now()->subDays(1),
        //     'version' => 4,
        //     'definition' => [
        //         'name' => 'Legacy CRM Webhook',
        //         'trigger' => 'webhook',
        //         'timeoutMs' => 60000,
        //         'retryPolicy' => ['maxAttempts' => 2, 'backoff' => 'exponential'],
        //         'steps' => [
        //             ['id' => 'receive-crm', 'label' => 'Receive CRM event', 'type' => 'http', 'dependsOn' => [], 'status' => 'success'],
        //             ['id' => 'map-fields', 'label' => 'Map fields', 'type' => 'script', 'dependsOn' => ['receive-crm'], 'status' => 'failed'],
        //             ['id' => 'sync-contact', 'label' => 'Sync contact', 'type' => 'http', 'dependsOn' => ['map-fields']],
        //         ],
        //     ],
        // ]);

        // $this->runRecord($tenant, $admin, $orderWorkflow, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa1042', 'success', 'webhook', 17200, null, now()->subMinutes(18));
        // $failedOrder = $this->runRecord($tenant, $admin, $orderWorkflow, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa1041', 'failed', 'webhook', 22900, 'reserve-stock', now()->subMinutes(38));
        // $this->runRecord($tenant, $admin, $reportWorkflow, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbb1040', 'running', 'scheduled', 31100, null, now()->subMinutes(45));
        // $this->runRecord($tenant, $admin, $reportWorkflow, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbb1039', 'timeout', 'scheduled', 120000, 'wait-settlement', now()->subHours(2));
        // $this->runRecord($tenant, $admin, $legacyWorkflow, 'cccccccc-cccc-4ccc-8ccc-cccccccc1037', 'failed', 'webhook', 49200, 'map-fields', now()->subHours(7));

        // $failedStep = $failedOrder->stepRuns()->where('step_id', 'reserve-stock')->first();
        // if ($failedStep) {
        //     AiFailureAnalysis::query()->updateOrCreate(
        //         ['workflow_run_id' => $failedOrder->id, 'step_run_id' => $failedStep->id],
        //         [
        //             'tenant_id' => $tenant->id,
        //             'requested_by_user_id' => $admin->id,
        //             'provider' => 'seed',
        //             'model' => 'seed-analysis',
        //             'prompt_version' => 'seed-1',
        //             'root_cause' => 'The reserve stock script received an insufficient_inventory response after retries.',
        //             'suggested_fix' => 'Check warehouse stock allocation for the order SKU, then rerun from the reserve stock step.',
        //             'summary' => 'Inventory reservation failed after retries.',
        //             'category' => 'code',
        //             'retry_recommended' => true,
        //             'confidence' => 'high',
        //             'raw_response' => ['mode' => 'seed'],
        //         ],
        //     );
        // }
    }

    private function user(Tenant $tenant, string $name, string $email, string $password, string $role): User
    {
        return User::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => $email],
            ['name' => $name, 'password' => Hash::make($password), 'role' => $role],
        );
    }

    private function workflow(Tenant $tenant, User $user, array $data): Workflow
    {
        $workflow = Workflow::query()->updateOrCreate(
            ['id' => $data['id']],
            [
                'tenant_id' => $tenant->id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'name' => $data['name'],
                'description' => $data['description'],
                'status' => $data['status'],
                'last_run_at' => $data['last_run_at'],
            ],
        );

        $version = WorkflowVersion::query()->updateOrCreate(
            ['workflow_id' => $workflow->id, 'version_number' => $data['version']],
            [
                'tenant_id' => $tenant->id,
                'created_by' => $user->id,
                'schema_version' => '1.0',
                'definition' => $data['definition'],
                'change_note' => 'Seeded workflow definition.',
            ],
        );

        $workflow->forceFill(['current_version_id' => $version->id])->save();

        return $workflow->refresh();
    }

    private function runRecord(Tenant $tenant, User $user, Workflow $workflow, string $id, string $status, string $trigger, int $duration, ?string $failedStepId, mixed $startedAt): WorkflowRun
    {
        $run = WorkflowRun::query()->updateOrCreate(
            ['id' => $id],
            [
                'tenant_id' => $tenant->id,
                'workflow_id' => $workflow->id,
                'workflow_version_id' => $workflow->current_version_id,
                'triggered_by_user_id' => $user->id,
                'status' => $status,
                'trigger_type' => $trigger,
                'input_payload' => ['seed' => true],
                'output_payload' => $status === 'success' ? ['ok' => true] : null,
                'error_message' => in_array($status, ['failed', 'timeout'], true) ? 'Seeded failure condition.' : null,
                'started_at' => $startedAt,
                'finished_at' => $status === 'running' ? null : (clone $startedAt)->addMilliseconds($duration),
                'duration_ms' => $duration,
            ],
        );

        foreach (($workflow->currentVersion->definition['steps'] ?? []) as $index => $step) {
            $stepStatus = $this->stepStatus($status, $step['id'], $failedStepId, $index);
            $stepRun = StepRun::query()->updateOrCreate(
                ['workflow_run_id' => $run->id, 'step_id' => $step['id']],
                [
                    'tenant_id' => $tenant->id,
                    'step_name' => $step['label'],
                    'step_type' => $step['type'],
                    'status' => $stepStatus,
                    'depends_on' => $step['dependsOn'] ?? [],
                    'attempt_count' => $stepStatus === 'failed' ? 3 : 1,
                    'max_retries' => $workflow->currentVersion->definition['retryPolicy']['maxAttempts'] ?? 0,
                    'backoff_seconds' => 2,
                    'error_message' => $stepStatus === 'failed' ? 'Seeded step failure.' : null,
                    'started_at' => $startedAt,
                    'finished_at' => in_array($stepStatus, ['pending', 'running'], true) ? null : (clone $startedAt)->addSeconds(($index + 1) * 4),
                    'duration_ms' => in_array($stepStatus, ['pending', 'running'], true) ? null : ($index + 1) * 4000,
                ],
            );

            ExecutionLog::query()->updateOrCreate(
                ['workflow_run_id' => $run->id, 'step_run_id' => $stepRun->id, 'message' => $step['label'].' '.$stepStatus.'.'],
                [
                    'tenant_id' => $tenant->id,
                    'level' => $stepStatus === 'failed' ? 'error' : ($stepStatus === 'running' ? 'info' : 'info'),
                    'metadata' => ['stepId' => $step['id']],
                ],
            );
        }

        return $run->refresh();
    }

    private function stepStatus(string $runStatus, string $stepId, ?string $failedStepId, int $index): string
    {
        if ($failedStepId === $stepId) {
            return $runStatus === 'timeout' ? 'failed' : 'failed';
        }

        if ($runStatus === 'running') {
            return $index === 0 ? 'success' : ($index === 1 ? 'running' : 'pending');
        }

        if (in_array($runStatus, ['failed', 'timeout'], true) && $failedStepId !== null) {
            return 'success';
        }

        return $runStatus === 'success' ? 'success' : 'pending';
    }
}
