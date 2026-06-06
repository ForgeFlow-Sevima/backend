<?php

namespace App\Services\Workflow\StepHandlers;

use App\Services\Workflow\WorkflowContextResolver;
use RuntimeException;

class ScriptStepHandler implements StepHandler
{
    public function __construct(private readonly WorkflowContextResolver $resolver = new WorkflowContextResolver()) {}

    public function handle(array $step, array $context): array
    {
        $config = $this->resolver->resolveConfig($step['config'] ?? [], $context);
        $input = is_array($config['input'] ?? null) ? $config['input'] : ($context['input'] ?? []);

        return match ($config['functionName'] ?? '') {
            'echoPayload' => ['payload' => $input, 'context' => $context],
            'mergePayload' => array_merge($context['input'] ?? [], $input),
            'countPreviousOutputs' => ['count' => count($context['steps'] ?? [])],
            default => throw new RuntimeException('Script function is not registered.'),
        };
    }
}
