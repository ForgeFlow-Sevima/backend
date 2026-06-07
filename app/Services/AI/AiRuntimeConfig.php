<?php

namespace App\Services\AI;

use Illuminate\Validation\ValidationException;

class AiRuntimeConfig
{
    public function provider(): string
    {
        $provider = strtolower(trim((string) config('flowforge_ai.provider', 'openrouter')));

        return $provider !== '' ? $provider : 'openrouter';
    }

    public function model(): string
    {
        $model = trim((string) config('flowforge_ai.model', ''));

        if ($model !== '') {
            return $model;
        }

        return (string) config('flowforge_ai.default_models.'.$this->provider(), 'anthropic/claude-3.5-sonnet');
    }

    public function timeout(): int
    {
        return (int) config('flowforge_ai.timeout', 300);
    }

    public function assertConfigured(string $errorKey): void
    {
        $provider = $this->provider();
        $providerConfig = config('ai.providers.'.$provider);

        if (! is_array($providerConfig)) {
            throw ValidationException::withMessages([
                $errorKey => 'AI provider "'.$provider.'" is not configured. Set AI_PROVIDER to one of: '.$this->providerList().'.',
            ]);
        }

        $envKey = config('flowforge_ai.api_key_env.'.$provider);
        if ($envKey === null) {
            return;
        }

        if (blank($providerConfig['key'] ?? null)) {
            throw ValidationException::withMessages([
                $errorKey => $this->providerLabel($provider).' API key is not configured. Set '.$envKey.' in backend .env.',
            ]);
        }
    }

    private function providerList(): string
    {
        return collect(config('ai.providers', []))->keys()->sort()->implode(', ');
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'openai' => 'OpenAI',
            'openrouter' => 'OpenRouter',
            'ollama' => 'Ollama',
            default => str($provider)->headline()->toString(),
        };
    }
}
