<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'workflow_run_id', 'step_run_id', 'requested_by_user_id', 'provider', 'model', 'prompt_version', 'prompt_text', 'input_context', 'summary', 'root_cause', 'suggested_fix', 'category', 'retry_recommended', 'confidence', 'raw_response', 'prompt_tokens', 'completion_tokens', 'total_tokens'])]
class AiFailureAnalysis extends Model
{
    use HasFactory, HasUuids;

    protected $attributes = [
        'category' => 'unknown',
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

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'input_context' => 'array',
            'retry_recommended' => 'boolean',
            'raw_response' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
        ];
    }
}
