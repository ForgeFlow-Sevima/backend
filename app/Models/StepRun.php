<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'workflow_run_id', 'step_id', 'step_name', 'step_type', 'status', 'depends_on', 'attempt_count', 'max_retries', 'backoff_seconds', 'input_payload', 'output_payload', 'error_message', 'started_at', 'finished_at', 'duration_ms'])]
class StepRun extends Model
{
    use HasFactory, HasUuids;

    protected $attributes = [
        'status' => 'pending',
        'attempt_count' => 0,
        'max_retries' => 0,
        'backoff_seconds' => 1,
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(StepRunAttempt::class);
    }

    public function executionLogs(): HasMany
    {
        return $this->hasMany(ExecutionLog::class);
    }

    public function aiFailureAnalyses(): HasMany
    {
        return $this->hasMany(AiFailureAnalysis::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(WorkflowApproval::class);
    }

    protected function casts(): array
    {
        return [
            'depends_on' => 'array',
            'attempt_count' => 'integer',
            'max_retries' => 'integer',
            'backoff_seconds' => 'integer',
            'input_payload' => 'array',
            'output_payload' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }
}
