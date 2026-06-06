<?php

use App\Models\StepRun;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowApproval;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dashboardRealtimeToken(object $test): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Realtime Tenant',
        'slug' => uniqid('realtime-'),
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => uniqid('admin-').'@flowforge.test',
        'role' => 'admin',
    ]);

    $token = $test->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk()->json('data.token');

    return [$token, $tenant, $user];
}

function dashboardRealtimeRun(Tenant $tenant, User $user, string $status, string $trigger): WorkflowRun
{
    $workflow = Workflow::query()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'name' => ucfirst($trigger).' '.$status,
        'description' => 'Dashboard realtime test',
        'status' => 'active',
    ]);

    $version = $workflow->versions()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'version_number' => 1,
        'definition' => ['name' => $workflow->name, 'trigger' => $trigger, 'timeoutMs' => 60000, 'retryPolicy' => ['maxAttempts' => 1, 'backoff' => 'exponential'], 'steps' => []],
        'change_note' => 'Dashboard realtime test',
    ]);

    $workflow->forceFill(['current_version_id' => $version->id])->save();

    return WorkflowRun::query()->create([
        'tenant_id' => $tenant->id,
        'workflow_id' => $workflow->id,
        'workflow_version_id' => $version->id,
        'triggered_by_user_id' => $user->id,
        'status' => $status,
        'trigger_type' => $trigger,
        'started_at' => now(),
    ]);
}

it('streams tenant dashboard updates with scheduled and approval runs', function () {
    [$token, $tenant, $user] = dashboardRealtimeToken($this);
    $scheduledRun = dashboardRealtimeRun($tenant, $user, 'running', 'scheduled');
    $approvalRun = dashboardRealtimeRun($tenant, $user, 'waiting_approval', 'manual');
    $manualRun = dashboardRealtimeRun($tenant, $user, 'running', 'manual');

    $stepRun = StepRun::query()->create([
        'tenant_id' => $tenant->id,
        'workflow_run_id' => $approvalRun->id,
        'step_id' => 'approve',
        'step_name' => 'Approve',
        'step_type' => 'approval',
        'status' => 'waiting_approval',
    ]);

    WorkflowApproval::query()->create([
        'tenant_id' => $tenant->id,
        'workflow_run_id' => $approvalRun->id,
        'step_run_id' => $stepRun->id,
        'title' => 'Approve run',
        'approvers' => ['admin'],
    ]);

    $response = $this->withToken($token)->get('/api/v1/dashboard/events?once=1');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

    $content = $response->streamedContent();

    $payload = json_decode(trim(str($content)->after('data: ')->toString()), true, flags: JSON_THROW_ON_ERROR)['data'];

    expect($content)->toContain('event: dashboard.updated')
        ->and(collect($payload['runningScheduledRuns'])->pluck('id')->all())->toBe([$scheduledRun->id])
        ->and(collect($payload['approvalRuns'])->pluck('id')->all())->toBe([$approvalRun->id])
        ->and(collect($payload['runningScheduledRuns'])->pluck('id')->all())->not->toContain($manualRun->id);
});
