<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class AiWorkflowDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:10', 'max:'.config('flowforge_ai.prompt_max_chars', 8000)],
            'context' => ['sometimes', 'array'],
        ];
    }
}
