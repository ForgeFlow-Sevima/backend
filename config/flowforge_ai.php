<?php

return [
    'provider' => env('AI_PROVIDER', 'gemini'),
    'model' => env('AI_GEMINI_MODEL', 'gemini-2.5-flash'),
    'prompt_max_chars' => (int) env('AI_WORKFLOW_PROMPT_MAX_CHARS', 8000),
    'max_steps' => (int) env('AI_WORKFLOW_MAX_STEPS', 20),
    'timeout' => (int) env('AI_WORKFLOW_TIMEOUT', 300),
    'failure_log_limit' => (int) env('AI_FAILURE_LOG_LIMIT', 100),
];
