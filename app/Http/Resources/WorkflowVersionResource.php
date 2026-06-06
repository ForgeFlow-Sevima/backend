<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $workflow = $this->relationLoaded('workflow') ? $this->workflow : null;
        $status = $workflow ? ($workflow->current_version_id === $this->id ? 'active' : 'previous') : 'active';

        return [
            'id' => $this->id,
            'workflowId' => $this->workflow_id,
            'version' => $this->version_number,
            'status' => $status,
            'createdAt' => $this->created_at?->toIso8601String(),
            'createdBy' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'changeSummary' => $this->change_note,
            'definition' => $this->definition,
        ];
    }
}
