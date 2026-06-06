<?php

namespace App\Jobs;

use App\Models\WorkflowRun;
use App\Services\Workflow\WorkflowExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteWorkflowRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3700;

    public function __construct(public readonly string $workflowRunId) {}

    public function handle(WorkflowExecutionService $executionService): void
    {
        $run = WorkflowRun::query()->with(['workflow.currentVersion', 'workflowVersion', 'triggeredBy'])->findOrFail($this->workflowRunId);
        $executionService->execute($run);
    }
}
