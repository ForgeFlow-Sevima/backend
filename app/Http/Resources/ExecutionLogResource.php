<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExecutionLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'runId' => $this->workflow_run_id,
            'stepRunId' => $this->step_run_id,
            'stepId' => $this->whenLoaded('stepRun', fn () => $this->stepRun?->step_id),
            'workflowName' => $this->whenLoaded('workflowRun', fn () => $this->workflowRun?->workflow?->name),
            'level' => ApiStatus::log($this->level),
            'message' => $this->message,
            'metadata' => $this->metadata ?? [],
            'timestamp' => $this->created_at?->toIso8601String(),
        ];
    }
}
