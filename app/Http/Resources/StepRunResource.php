<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StepRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'runId' => $this->workflow_run_id,
            'stepId' => $this->step_id,
            'stepName' => $this->step_name,
            'stepType' => $this->step_type,
            'status' => ApiStatus::run($this->status),
            'startedAt' => $this->started_at?->toIso8601String(),
            'finishedAt' => $this->finished_at?->toIso8601String(),
            'durationMs' => $this->duration_ms ?? 0,
            'attemptsCount' => $this->attempt_count,
            'errorMessage' => $this->error_message,
        ];
    }
}
