<?php

return [
    'provider' => env('AI_PROVIDER', 'openrouter'),
    'model' => env('AI_MODEL', env('AI_GEMINI_MODEL')),
    'default_models' => [
        'anthropic' => 'claude-3-5-sonnet-latest',
        'gemini' => 'gemini-2.5-flash',
        'groq' => 'openai/gpt-oss-120b',
        'ollama' => 'llama3.1',
        'openai' => 'gpt-4.1-mini',
        'openrouter' => 'anthropic/claude-3.5-sonnet',
    ],
    'api_key_env' => [
        'anthropic' => 'ANTHROPIC_API_KEY',
        'gemini' => 'GEMINI_API_KEY',
        'groq' => 'GROQ_API_KEY',
        'mistral' => 'MISTRAL_API_KEY',
        'ollama' => null,
        'openai' => 'OPENAI_API_KEY',
        'openrouter' => 'OPENROUTER_API_KEY',
    ],
    'prompt_max_chars' => (int) env('AI_WORKFLOW_PROMPT_MAX_CHARS', 8000),
    'max_steps' => (int) env('AI_WORKFLOW_MAX_STEPS', 20),
    'timeout' => (int) env('AI_WORKFLOW_TIMEOUT', 300),
    'failure_log_limit' => (int) env('AI_FAILURE_LOG_LIMIT', 100),
];
