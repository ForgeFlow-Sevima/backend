<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['tenant_id', 'name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $attributes = [
        'role' => 'viewer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdWorkflows(): HasMany
    {
        return $this->hasMany(Workflow::class, 'created_by');
    }

    public function updatedWorkflows(): HasMany
    {
        return $this->hasMany(Workflow::class, 'updated_by');
    }

    public function workflowVersions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class, 'created_by');
    }

    public function workflowRuns(): HasMany
    {
        return $this->hasMany(WorkflowRun::class, 'triggered_by_user_id');
    }

    public function webhookTriggers(): HasMany
    {
        return $this->hasMany(WebhookTrigger::class, 'created_by');
    }

    public function scheduledTriggers(): HasMany
    {
        return $this->hasMany(ScheduledTrigger::class, 'created_by');
    }

    public function aiWorkflowGenerations(): HasMany
    {
        return $this->hasMany(AiWorkflowGeneration::class, 'requested_by_user_id');
    }

    public function aiFailureAnalyses(): HasMany
    {
        return $this->hasMany(AiFailureAnalysis::class, 'requested_by_user_id');
    }

    public function sseAccessTokens(): HasMany
    {
        return $this->hasMany(SseAccessToken::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function personalAccessTokens(): MorphMany
    {
        return $this->morphMany(PersonalAccessToken::class, 'tokenable');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
