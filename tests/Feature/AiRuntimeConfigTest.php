<?php

use App\Services\AI\AiRuntimeConfig;
use Illuminate\Validation\ValidationException;

it('resolves openrouter provider and configured model', function () {
    config()->set('flowforge_ai.provider', 'openrouter');
    config()->set('flowforge_ai.model', 'openai/gpt-4o-mini');
    config()->set('ai.providers.openrouter.key', 'test-openrouter-key');

    $config = new AiRuntimeConfig;

    expect($config->provider())->toBe('openrouter')
        ->and($config->model())->toBe('openai/gpt-4o-mini');

    expect(fn () => $config->assertConfigured('prompt'))->not->toThrow(ValidationException::class);
});

it('falls back to provider default model when ai model is empty', function () {
    config()->set('flowforge_ai.provider', 'openrouter');
    config()->set('flowforge_ai.model', null);

    expect((new AiRuntimeConfig)->model())->toBe('anthropic/claude-3.5-sonnet');
});

it('reports missing provider key with matching env name', function () {
    config()->set('flowforge_ai.provider', 'openrouter');
    config()->set('ai.providers.openrouter.key', null);

    try {
        (new AiRuntimeConfig)->assertConfigured('prompt');
        $this->fail('Expected validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['prompt'][0])->toBe('OpenRouter API key is not configured. Set OPENROUTER_API_KEY in backend .env.');
    }
});

it('reports unsupported providers before calling the llm', function () {
    config()->set('flowforge_ai.provider', 'unknown-provider');

    try {
        (new AiRuntimeConfig)->assertConfigured('ai');
        $this->fail('Expected validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['ai'][0])->toContain('AI provider "unknown-provider" is not configured.');
    }
});
