<?php

namespace App\Services\Workflow;

use Illuminate\Validation\ValidationException;

class WorkflowDefinitionValidator
{
    /**
     * @return array<int, string>
     */
    public function topologicalOrder(array $definition): array
    {
        $steps = collect($definition['steps'] ?? [])->keyBy('id');
        $incoming = [];
        $children = [];

        foreach ($steps as $id => $step) {
            $incoming[$id] = count($step['dependsOn'] ?? []);
            foreach ($step['dependsOn'] ?? [] as $dependency) {
                $children[$dependency] ??= [];
                $children[$dependency][] = $id;
            }
        }

        $queue = collect($incoming)->filter(fn ($count) => $count === 0)->keys()->values()->all();
        $order = [];

        while ($queue !== []) {
            $id = array_shift($queue);
            $order[] = $id;

            foreach ($children[$id] ?? [] as $child) {
                $incoming[$child]--;
                if ($incoming[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }

        if (count($order) !== $steps->count()) {
            throw ValidationException::withMessages([
                'definition.steps' => 'Workflow steps must be a directed acyclic graph.',
            ]);
        }

        return $order;
    }

    public function validate(array $definition): array
    {
        $steps = collect($definition['steps'] ?? []);
        $ids = $steps->pluck('id')->filter()->values();

        if ($steps->isEmpty()) {
            throw ValidationException::withMessages(['definition.steps' => 'Workflow requires at least one step.']);
        }

        if ($ids->count() !== $steps->count() || $ids->unique()->count() !== $ids->count()) {
            throw ValidationException::withMessages(['definition.steps' => 'Step ids must be present and unique.']);
        }

        $knownIds = $ids->flip();
        foreach ($steps as $index => $step) {
            foreach ($step['dependsOn'] ?? [] as $dependency) {
                if (! $knownIds->has($dependency)) {
                    throw ValidationException::withMessages([
                        "definition.steps.$index.dependsOn" => "Unknown dependency [$dependency].",
                    ]);
                }
            }

            $this->validateConfig($step, $index);
        }

        $this->topologicalOrder($definition);

        return $definition;
    }

    private function validateConfig(array $step, int $index): void
    {
        $config = $step['config'] ?? [];

        match ($step['type'] ?? null) {
            'http' => $this->requireString($config, 'url', "definition.steps.$index.config.url"),
            'delay' => $this->requirePositiveInt($config, 'durationMs', "definition.steps.$index.config.durationMs"),
            'condition' => $this->requireCondition($config, $index),
            'script' => $this->requireString($config, 'functionName', "definition.steps.$index.config.functionName"),
            'approval' => $this->requireString($config, 'title', "definition.steps.$index.config.title"),
            default => throw ValidationException::withMessages(["definition.steps.$index.type" => 'Unsupported step type.']),
        };
    }

    private function requireString(array $config, string $key, string $field): void
    {
        if (! isset($config[$key]) || ! is_string($config[$key]) || trim($config[$key]) === '') {
            throw ValidationException::withMessages([$field => 'This field is required.']);
        }
    }

    private function requirePositiveInt(array $config, string $key, string $field): void
    {
        if (! isset($config[$key]) || ! is_numeric($config[$key]) || (int) $config[$key] < 1) {
            throw ValidationException::withMessages([$field => 'This field must be a positive integer.']);
        }
    }

    private function requireCondition(array $config, int $index): void
    {
        foreach (['left', 'operator', 'right'] as $key) {
            $this->requireString($config, $key, "definition.steps.$index.config.$key");
        }

        if (! in_array($config['operator'], ['equals', 'not_equals', 'contains', 'greater_than', 'less_than'], true)) {
            throw ValidationException::withMessages(["definition.steps.$index.config.operator" => 'Unsupported operator.']);
        }

        foreach (['onTrue', 'onFalse'] as $branch) {
            if (isset($config[$branch]) && ! is_array($config[$branch])) {
                throw ValidationException::withMessages(["definition.steps.$index.config.$branch" => 'Branch targets must be an array of step ids.']);
            }
        }
    }
}
