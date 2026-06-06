<?php

use App\Models\AiWorkflowGeneration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\WorkflowDraftGenerationResult;
use App\Services\AI\WorkflowDraftGenerator;
use App\Services\Workflow\WorkflowDefinitionValidator;
use App\Support\MockApiUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function aiDraftToken(object $test): string
{
    $tenant = Tenant::query()->create([
        'name' => 'AI Tenant',
        'slug' => uniqid('ai-tenant-'),
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => uniqid('admin-').'@flowforge.test',
        'role' => 'admin',
    ]);

    return $test->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk()->json('data.token');
}

it('generates a workflow draft through the llm generator contract', function () {
    config()->set('flowforge_ai.provider', 'gemini');
    config()->set('flowforge_ai.model', 'gemini-2.5-flash');

    $definition = [
        'name' => 'Refund Workflow',
        'trigger' => 'manual',
        'timeoutMs' => 60000,
        'retryPolicy' => ['maxAttempts' => 1, 'backoff' => 'exponential'],
        'steps' => [
            [
                'id' => 'fetch-order',
                'label' => 'Fetch order',
                'type' => 'http',
                'dependsOn' => [],
                'config' => ['url' => MockApiUrls::order()],
            ],
        ],
    ];

    $mock = Mockery::mock(WorkflowDraftGenerator::class);
    $mock->shouldReceive('generate')
        ->once()
        ->with('Create a refund workflow', [])
        ->andReturn(new WorkflowDraftGenerationResult($definition, ['phase' => 'initial'], ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30], false, 0));
    $this->app->instance(WorkflowDraftGenerator::class, $mock);

    $this->withToken(aiDraftToken($this))
        ->postJson('/api/v1/ai/workflow-drafts', ['prompt' => 'Create a refund workflow'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Refund Workflow')
        ->assertJsonPath('data.steps.0.type', 'http');

    $generation = AiWorkflowGeneration::query()->firstOrFail();
    expect($generation->provider)->toBe('gemini')
        ->and($generation->model)->toBe('gemini-2.5-flash')
        ->and($generation->status)->toBe('success')
        ->and($generation->total_tokens)->toBe(30);
});

it('records failed workflow draft generation validation errors', function () {
    $mock = Mockery::mock(WorkflowDraftGenerator::class);
    $mock->shouldReceive('generate')
        ->once()
        ->andThrow(ValidationException::withMessages(['prompt' => 'Gemini API key is not configured. Set GEMINI_API_KEY in backend .env.']));
    $this->app->instance(WorkflowDraftGenerator::class, $mock);

    $this->withToken(aiDraftToken($this))
        ->postJson('/api/v1/ai/workflow-drafts', ['prompt' => 'Create a refund workflow'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.prompt.0', 'Gemini API key is not configured. Set GEMINI_API_KEY in backend .env.');

    $generation = AiWorkflowGeneration::query()->firstOrFail();
    expect($generation->status)->toBe('failed')
        ->and($generation->validation_errors['prompt'][0])->toContain('Gemini API key');
});

it('fills missing type configs before workflow validation', function () {
    config()->set('app.url', 'http://backend.test');

    $generator = new WorkflowDraftGenerator(new WorkflowDefinitionValidator());
    $method = new ReflectionMethod($generator, 'normalize');

    $definition = $method->invoke($generator, [
        'name' => 'Refund Workflow',
        'trigger' => 'manual',
        'timeoutMs' => 60000,
        'retryPolicy' => ['maxAttempts' => 1, 'backoff' => 'exponential'],
        'steps' => [
            [
                'id' => 'fetch-order',
                'label' => 'Fetch order',
                'type' => 'http',
                'dependsOn' => [],
                'config' => [],
            ],
            [
                'id' => 'score-risk',
                'label' => 'Score risk',
                'type' => 'script',
                'dependsOn' => ['fetch-order'],
                'config' => [],
            ],
        ],
    ]);

    expect($definition['steps'][0]['config']['url'])->toBe('http://backend.test/api/mock/orders/{{ input.body.orderId }}')
        ->and($definition['steps'][0]['config']['method'])->toBe('GET')
        ->and($definition['steps'][1]['config']['functionName'])->toBe('echoPayload');

    expect(fn () => (new WorkflowDefinitionValidator())->validate($definition))->not->toThrow(ValidationException::class);
});

it('rejects semantically incomplete parallel notification drafts', function () {
    $generator = new WorkflowDraftGenerator(new WorkflowDefinitionValidator());
    $method = new ReflectionMethod($generator, 'validateSemanticFit');

    $definition = [
        'name' => 'Parallel Customer Notification Workflow',
        'trigger' => 'manual',
        'timeoutMs' => 90000,
        'retryPolicy' => ['maxAttempts' => 3, 'backoff' => 'exponential'],
        'steps' => [
            [
                'id' => 'fetch-order-details',
                'label' => 'Fetch Order Details',
                'type' => 'http',
                'dependsOn' => [],
                'config' => ['method' => 'GET', 'url' => MockApiUrls::order(), 'headers' => [], 'body' => null, 'timeoutMs' => 10000],
            ],
        ],
    ];

    expect(fn () => $method->invoke($generator, 'Create a parallel customer notification workflow with email, SMS, and Slack notifications.', $definition))
        ->toThrow(ValidationException::class);
});

it('builds a complete fallback for parallel customer notifications', function () {
    config()->set('app.url', 'http://backend.test');

    $generator = new WorkflowDraftGenerator(new WorkflowDefinitionValidator());
    $method = new ReflectionMethod($generator, 'fallbackDefinition');

    $definition = $method->invoke($generator, 'Create a parallel customer notification workflow with email, SMS, and Slack notifications.');
    $steps = collect($definition['steps']);

    expect($steps->pluck('id')->all())->toBe(['fetch-order', 'notify-email', 'notify-sms', 'notify-slack', 'summarize-notifications'])
        ->and($steps->firstWhere('id', 'notify-email')['config']['body']['channel'])->toBe('email')
        ->and($steps->firstWhere('id', 'notify-sms')['config']['body']['channel'])->toBe('sms')
        ->and($steps->firstWhere('id', 'notify-slack')['config']['body']['channel'])->toBe('slack')
        ->and($steps->firstWhere('id', 'summarize-notifications')['dependsOn'])->toBe(['notify-email', 'notify-sms', 'notify-slack']);

    expect(fn () => (new WorkflowDefinitionValidator())->validate($definition))->not->toThrow(ValidationException::class);
});
