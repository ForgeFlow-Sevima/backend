<?php

use App\Models\AiFailureAnalysis;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\AI\AiFailureAnalysisGenerator;
use App\Services\AI\FailureAnalysisGenerationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function aiFailureRun(object $test, string $status = 'failed'): array
{
    $tenant = Tenant::query()->create(['name' => 'AI Failure Tenant', 'slug' => uniqid('ai-failure-'), 'status' => 'active']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => uniqid('admin-').'@flowforge.test', 'role' => 'admin']);
    $workflow = Workflow::query()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'name' => 'Notification Workflow',
        'description' => 'Test workflow',
        'status' => 'active',
    ]);
    $version = $workflow->versions()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'version_number' => 1,
        'definition' => [
            'name' => 'Notification Workflow',
            'trigger' => 'manual',
            'timeoutMs' => 60000,
            'retryPolicy' => ['maxAttempts' => 1, 'backoff' => 'exponential'],
            'steps' => [
                ['id' => 'notify-customer', 'label' => 'Notify customer', 'type' => 'http', 'dependsOn' => [], 'config' => ['method' => 'POST', 'url' => '/api/mock/notifications', 'body' => null]],
            ],
        ],
        'change_note' => 'Test version',
    ]);
    $workflow->forceFill(['current_version_id' => $version->id])->save();
    $run = WorkflowRun::query()->create([
        'tenant_id' => $tenant->id,
        'workflow_id' => $workflow->id,
        'workflow_version_id' => $version->id,
        'triggered_by_user_id' => $user->id,
        'status' => $status,
        'trigger_type' => 'manual',
        'input_payload' => ['body' => ['orderId' => '1002']],
        'error_message' => $status === 'failed' ? 'HTTP request failed validation.' : null,
    ]);
    $stepRun = $run->stepRuns()->create([
        'tenant_id' => $tenant->id,
        'step_id' => 'notify-customer',
        'step_name' => 'Notify customer',
        'step_type' => 'http',
        'status' => $status === 'failed' ? 'failed' : 'success',
        'depends_on' => [],
        'attempt_count' => 1,
        'max_retries' => 1,
        'error_message' => $status === 'failed' ? 'The channel field is required.' : null,
    ]);
    $run->executionLogs()->create([
        'tenant_id' => $tenant->id,
        'step_run_id' => $stepRun->id,
        'level' => 'error',
        'message' => 'Mock notification failed validation: channel is required.',
        'metadata' => ['field' => 'channel'],
    ]);

    $token = $test->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->json('data.token');

    return [$token, $run->refresh(), $stepRun];
}

it('stores llm failure analysis from evidence context', function () {
    [$token, $run, $stepRun] = aiFailureRun($this);
    config()->set('flowforge_ai.provider', 'openrouter');
    config()->set('flowforge_ai.model', 'anthropic/claude-3.5-sonnet');

    $mock = Mockery::mock(AiFailureAnalysisGenerator::class);
    $mock->shouldReceive('generate')->once()->andReturn(new FailureAnalysisGenerationResult(
        [
            'summary' => 'Notification body missed required channel.',
            'rootCause' => 'Step notify-customer failed because the log says channel is required and the step body is null.',
            'suggestedFix' => 'Add body.channel, body.recipient, and body.message to the HTTP notification step before rerun.',
            'affectedStepId' => 'notify-customer',
            'category' => 'configuration',
            'retryRecommended' => true,
            'confidence' => 'high',
            'evidence' => ['Mock notification failed validation: channel is required.', 'definition config body is null'],
        ],
        'prompt text',
        ['run' => ['id' => $run->id]],
        ['mode' => 'llm'],
        ['prompt_tokens' => 11, 'completion_tokens' => 12, 'total_tokens' => 23],
    ));
    $this->app->instance(AiFailureAnalysisGenerator::class, $mock);

    $this->withToken($token)
        ->postJson("/api/v1/runs/{$run->id}/ai-analysis")
        ->assertCreated()
        ->assertJsonPath('data.rootCause', 'Step notify-customer failed because the log says channel is required and the step body is null.')
        ->assertJsonPath('data.affectedStepId', 'notify-customer')
        ->assertJsonPath('data.category', 'configuration')
        ->assertJsonPath('data.retryRecommended', true);

    $analysis = AiFailureAnalysis::query()->firstOrFail();
    expect($analysis->provider)->toBe('openrouter')
        ->and($analysis->model)->toBe('anthropic/claude-3.5-sonnet')
        ->and($analysis->step_run_id)->toBe($stepRun->id)
        ->and($analysis->total_tokens)->toBe(23);

    expect(AuditLog::query()->where('action', 'ai.analysis.created')->exists())->toBeTrue();
});

it('rejects failure analysis when gemini is not configured', function () {
    [$token, $run] = aiFailureRun($this);
    config()->set('flowforge_ai.provider', 'gemini');
    config()->set('ai.providers.gemini.key', null);

    $this->withToken($token)
        ->postJson("/api/v1/runs/{$run->id}/ai-analysis")
        ->assertUnprocessable()
        ->assertJsonPath('errors.ai.0', 'Gemini API key is not configured. Set GEMINI_API_KEY in backend .env.');
});

it('keeps ai analysis limited to failed or timed out runs', function () {
    [$token, $run] = aiFailureRun($this, 'success');

    $this->withToken($token)
        ->postJson("/api/v1/runs/{$run->id}/ai-analysis")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'AI analysis is only available for failed or timed out runs.');
});

it('builds evidence context from failed step, logs, and definition config', function () {
    [, $run] = aiFailureRun($this);
    $context = (new AiFailureAnalysisGenerator)->buildContext($run->fresh());

    expect($context['failedStep']['stepId'])->toBe('notify-customer')
        ->and($context['failedStep']['errorMessage'])->toBe('The channel field is required.')
        ->and($context['failedStep']['definitionConfig']['body'])->toBeNull()
        ->and($context['logs'][0]['message'])->toContain('channel is required')
        ->and($context['workflow']['definition']['steps'][0]['id'])->toBe('notify-customer');
});
