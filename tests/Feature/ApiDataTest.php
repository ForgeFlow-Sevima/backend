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

it('paginates workflow index with custom per page metadata', function () {
    $token = seededToken($this);

    $this->withToken($token)
        ->getJson('/api/v1/workflows?perPage=2&page=1')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.perPage', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.lastPage', 2)
        ->assertJsonPath('meta.from', 1)
        ->assertJsonPath('meta.to', 2);
});

it('paginates run index with trigger filtering', function () {
    $token = seededToken($this);

    $response = $this->withToken($token)
        ->getJson('/api/v1/runs?trigger=scheduled&perPage=1&page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.perPage', 1);

    expect(collect($response->json('data'))->pluck('trigger')->unique()->all())->toBe(['scheduled']);
});

it('paginates global logs with level filtering', function () {
    $token = seededToken($this);

    $response = $this->withToken($token)
        ->getJson('/api/v1/logs?level=error&perPage=1&page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.perPage', 1);

    expect(collect($response->json('data'))->pluck('level')->unique()->all())->toBe(['error']);
});

it('rejects invalid pagination parameters', function () {
    $token = seededToken($this);

    $this->withToken($token)
        ->getJson('/api/v1/workflows?perPage=101')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['perPage']);

    $this->withToken($token)
        ->getJson('/api/v1/runs?page=0')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['page']);

    $this->withToken($token)
        ->getJson('/api/v1/logs?perPage=501')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['perPage']);
});
