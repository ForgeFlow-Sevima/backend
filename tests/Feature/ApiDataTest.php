<?php

use App\Models\ExecutionLog;
use App\Models\StepRun;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seededToken(object $test): string
{
    $test->seed(UserSeeder::class);

    return $test->postJson('/api/v1/auth/login', [
        'email' => 'admin@flowforge.test',
        'password' => 'password',
    ])->assertOk()->json('data.token');
}

it('serves seeded dashboard summary and workflow data from database', function () {
    $token = seededToken($this);

    $this->withToken($token)
        ->getJson('/api/v1/dashboard/summary')
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['totalWorkflows', 'activeWorkflows', 'successRate', 'failedRuns', 'recentRuns', 'runVolume'],
        ])
        ->assertJsonPath('data.totalWorkflows', 3);

    $this->withToken($token)
        ->getJson('/api/v1/workflows')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Legacy CRM Webhook');
});

it('serves global logs with filters scoped to the authenticated tenant', function () {
    $token = seededToken($this);
    $tenant = Tenant::query()->create(['name' => 'Other Tenant', 'slug' => 'other-tenant', 'status' => 'active']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    ExecutionLog::query()->create([
        'tenant_id' => $tenant->id,
        'workflow_run_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa1042',
        'level' => 'error',
        'message' => 'Other tenant secret log',
        'metadata' => ['user' => $user->id],
    ]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/logs?level=error&query=Reserve')
        ->assertOk();

    expect(collect($response->json('data'))->pluck('message')->all())
        ->toContain('Reserve stock failed.')
        ->not->toContain('Other tenant secret log');
});

it('serves run logs filtered by step run', function () {
    $token = seededToken($this);
    $stepRun = StepRun::query()
        ->where('workflow_run_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa1042')
        ->where('step_id', 'receive-order')
        ->firstOrFail();

    $response = $this->withToken($token)
        ->getJson("/api/v1/runs/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa1042/logs?stepRunId={$stepRun->id}")
        ->assertOk()
        ->assertJsonPath('data.0.stepRunId', $stepRun->id)
        ->assertJsonPath('data.0.stepId', 'receive-order');

    expect(collect($response->json('data'))->pluck('stepRunId')->unique()->all())->toBe([$stepRun->id]);
});

