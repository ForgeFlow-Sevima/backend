<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'requested_by_user_id', 'provider', 'model', 'user_prompt', 'prompt_version', 'system_prompt', 'generated_definition', 'validation_errors', 'status', 'raw_response', 'prompt_tokens', 'completion_tokens', 'total_tokens'])]
class AiWorkflowGeneration extends Model
{
    use HasFactory, HasUuids;

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'generated_definition' => 'array',
            'validation_errors' => 'array',
            'raw_response' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
        ];
    }
}
