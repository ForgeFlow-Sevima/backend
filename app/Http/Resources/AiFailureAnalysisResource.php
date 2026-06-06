<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiFailureAnalysisResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'runId' => $this->workflow_run_id,
            'rootCause' => $this->root_cause,
            'suggestedFix' => $this->suggested_fix,
            'affectedStepId' => $this->whenLoaded('stepRun', fn () => $this->stepRun?->step_id),
            'confidence' => match ($this->confidence) {
                'high' => 0.9,
                'medium' => 0.65,
                'low' => 0.35,
                default => 0,
            },
            'category' => $this->category,
            'retryRecommended' => (bool) $this->retry_recommended,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
