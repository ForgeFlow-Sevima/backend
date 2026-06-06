<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'created_by', 'updated_by', 'current_version_id', 'name', 'description', 'status', 'last_run_at'])]
class Workflow extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $attributes = [
        'status' => 'active',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    public function webhookTriggers(): HasMany
    {
        return $this->hasMany(WebhookTrigger::class);
    }

    public function scheduledTriggers(): HasMany
    {
        return $this->hasMany(ScheduledTrigger::class);
    }

    protected function casts(): array
    {
        return [
            'last_run_at' => 'datetime',
        ];
    }
}
