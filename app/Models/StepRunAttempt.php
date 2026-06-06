<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'workflow_run_id', 'step_run_id', 'attempt_number', 'status', 'input_payload', 'output_payload', 'error_message', 'started_at', 'finished_at', 'duration_ms'])]
class StepRunAttempt extends Model
{
    use HasFactory, HasUuids;

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    public function stepRun(): BelongsTo
    {
        return $this->belongsTo(StepRun::class);
    }

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'input_payload' => 'array',
            'output_payload' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }
}
