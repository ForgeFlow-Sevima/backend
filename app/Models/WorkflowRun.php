<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'workflow_id', 'workflow_version_id', 'triggered_by_user_id', 'status', 'trigger_type', 'input_payload', 'output_payload', 'error_message', 'started_at', 'finished_at', 'duration_ms'])]
class WorkflowRun extends Model
{
    use HasFactory, HasUuids;

    protected $attributes = [
        'status' => 'pending',
        'trigger_type' => 'manual',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function workflowVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function stepRuns(): HasMany
    {
        return $this->hasMany(StepRun::class);
    }

    public function stepRunAttempts(): HasMany
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

    public function sseAccessTokens(): HasMany
    {
        return $this->hasMany(SseAccessToken::class);
    }

    protected function casts(): array
    {
        return [
            'input_payload' => 'array',
            'output_payload' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }
}
