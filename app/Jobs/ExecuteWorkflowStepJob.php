<?php

namespace App\Jobs;

use App\Models\WorkflowRun;
use App\Services\Workflow\WorkflowExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteWorkflowStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3700;

    public function __construct(public readonly string $workflowRunId, public readonly string $stepId) {}

    public function handle(WorkflowExecutionService $executionService): void
    {
        $executionService->executeSingleStep($this->workflowRunId, $this->stepId);

        $run = WorkflowRun::query()->find($this->workflowRunId);
        if ($run && in_array($run->status, ['running', 'pending'], true)) {
            ExecuteWorkflowRunJob::dispatch($run->id);
        }
    }
}
