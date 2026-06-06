<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $failedStep = $this->relationLoaded('stepRuns')
            ? $this->stepRuns->first(fn ($stepRun) => $stepRun->status === 'failed')
            : null;

        return [
            'id' => $this->id,
            'workflowId' => $this->workflow_id,
            'workflowName' => $this->whenLoaded('workflow', fn () => $this->workflow?->name),
            'status' => ApiStatus::run($this->status),
            'startedAt' => $this->started_at?->toIso8601String() ?? $this->created_at?->toIso8601String(),
            'finishedAt' => $this->finished_at?->toIso8601String(),
            'durationMs' => $this->duration_ms ?? 0,
            'trigger' => $this->trigger_type,
            'failedStepId' => $failedStep?->step_id,
            'steps' => StepRunResource::collection($this->whenLoaded('stepRuns')),
            'logs' => ExecutionLogResource::collection($this->whenLoaded('executionLogs')),
            'aiAnalysis' => AiFailureAnalysisResource::collection($this->whenLoaded('aiFailureAnalyses')),
            'approvals' => WorkflowApprovalResource::collection($this->whenLoaded('approvals')),
        ];
    }
}
