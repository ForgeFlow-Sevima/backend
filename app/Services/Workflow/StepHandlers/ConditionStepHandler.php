<?php

namespace App\Services\Workflow\StepHandlers;

use App\Services\Workflow\WorkflowContextResolver;

class ConditionStepHandler implements StepHandler
{
    public function __construct(private readonly WorkflowContextResolver $resolver = new WorkflowContextResolver) {}

    public function handle(array $step, array $context): array
    {
        $config = $step['config'] ?? [];
        $left = $this->resolver->resolveValue((string) ($config['left'] ?? ''), $context);
        $right = $this->resolver->resolveValue((string) ($config['right'] ?? ''), $context);
        $operator = $config['operator'] ?? 'equals';

        $matched = match ($operator) {
            'not_equals' => (string) $left !== (string) $right,
            'contains' => str_contains((string) $left, (string) $right),
            'greater_than' => (float) $left > (float) $right,
            'less_than' => (float) $left < (float) $right,
            default => (string) $left === (string) $right,
        };

        $selected = $matched ? ($config['onTrue'] ?? []) : ($config['onFalse'] ?? []);
        $selected = is_array($selected) ? $selected : array_filter([$selected]);

        return [
            'matched' => $matched,
            'nextStepId' => $selected[0] ?? null,
            'selectedStepIds' => array_values($selected),
            'left' => $left,
            'right' => $right,
            'operator' => $operator,
        ];
    }
}
