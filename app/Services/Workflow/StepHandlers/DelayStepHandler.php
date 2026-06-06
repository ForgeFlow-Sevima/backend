<?php

namespace App\Services\Workflow\StepHandlers;

class DelayStepHandler implements StepHandler
{
    public function handle(array $step, array $context): array
    {
        $durationMs = min((int) (($step['config']['durationMs'] ?? 1000)), 10000);
        usleep($durationMs * 1000);

        return ['delayedMs' => $durationMs, 'input' => $context['input'] ?? []];
    }
}
