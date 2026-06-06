<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'workflow_run_id', 'step_run_id', 'status', 'title', 'description', 'approvers', 'decided_by_user_id', 'decision_note', 'expires_at', 'decided_at'])]
class WorkflowApproval extends Model
{
    use HasFactory, HasUuids;

    protected $attributes = [
        'status' => 'pending',
    ];

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

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'approvers' => 'array',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
