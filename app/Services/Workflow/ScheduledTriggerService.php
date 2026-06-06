<?php

namespace App\Services\Workflow;

use App\Jobs\ExecuteWorkflowRunJob;
use App\Models\ScheduledTrigger;
use App\Models\WorkflowRun;
use Carbon\CarbonImmutable;

class ScheduledTriggerService
{
    public function __construct(private readonly WorkflowExecutionService $executionService) {}

    /** @return array<int, WorkflowRun> */
    public function runDue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $runs = [];

        ScheduledTrigger::query()
            ->with('workflow.currentVersion')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now))
            ->chunkById(50, function ($triggers) use ($now, &$runs): void {
                foreach ($triggers as $trigger) {
                    if (! $this->isRunnable($trigger)) {
                        continue;
                    }

                    $run = $this->trigger($trigger, ['scheduledAt' => $now->toIso8601String()]);
                    $runs[] = $run;
                }
            });

        return $runs;
    }

    public function trigger(ScheduledTrigger $trigger, array $input = []): WorkflowRun
    {
        $trigger->load('workflow.currentVersion');
        abort_unless($this->isRunnable($trigger), 422, 'Scheduled trigger is not runnable.');

        $run = $this->executionService->createRun($trigger->workflow, null, $input, 'scheduled');
        $trigger->forceFill([
            'last_run_at' => now(),
            'next_run_at' => $this->nextRunAt($trigger->cron_expression, $trigger->timezone),
        ])->save();

        ExecuteWorkflowRunJob::dispatch($run->id);

        return $run;
    }

    public function nextRunAt(string $cron, string $timezone): CarbonImmutable
    {
        $now = CarbonImmutable::now($timezone)->startOfMinute();

        if ($cron === '* * * * *') {
            return $now->addMinute()->utc();
        }

        if (preg_match('/^\*\/(\d+) \* \* \* \*$/', $cron, $matches)) {
            return $now->addMinutes(max(1, (int) $matches[1]))->utc();
        }

        if (preg_match('/^(\d{1,2}) (\d{1,2}) \* \* \*$/', $cron, $matches)) {
            $candidate = $now->setTime((int) $matches[2], (int) $matches[1]);
            return ($candidate->greaterThan($now) ? $candidate : $candidate->addDay())->utc();
        }

        return $now->addMinute()->utc();
    }

    private function isRunnable(ScheduledTrigger $trigger): bool
    {
        return $trigger->workflow
            && $trigger->workflow->status === 'active'
            && (($trigger->workflow->currentVersion?->definition['trigger'] ?? null) === 'scheduled');
    }
}
