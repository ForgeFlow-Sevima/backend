<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'status'])]
class Tenant extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $attributes = [
        'status' => 'active',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    public function workflowVersions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class);
    }

    public function workflowRuns(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
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

    public function webhookTriggers(): HasMany
    {
        return $this->hasMany(WebhookTrigger::class);
    }

    public function scheduledTriggers(): HasMany
    {
        return $this->hasMany(ScheduledTrigger::class);
    }

    public function aiWorkflowGenerations(): HasMany
    {
        return $this->hasMany(AiWorkflowGeneration::class);
    }

    public function aiFailureAnalyses(): HasMany
    {
        return $this->hasMany(AiFailureAnalysis::class);
    }

    public function sseAccessTokens(): HasMany
    {
        return $this->hasMany(SseAccessToken::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
