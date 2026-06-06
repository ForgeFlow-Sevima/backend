<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'runId' => $this->workflow_run_id,
            'stepRunId' => $this->step_run_id,
            'status' => $this->status,
            'title' => $this->title,
            'description' => $this->description,
            'approvers' => $this->approvers ?? [],
            'decidedBy' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'decisionNote' => $this->decision_note,
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'decidedAt' => $this->decided_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
