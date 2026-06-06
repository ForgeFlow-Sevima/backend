<?php

namespace App\Http\Requests\Api\V1;

class ValidateWorkflowDefinitionRequest extends WorkflowUpsertRequest
{
    public function rules(): array
    {
        return [
            'definition' => ['required', 'array'],
            'definition.name' => ['required', 'string', 'max:150'],
            'definition.trigger' => ['required', 'in:manual,webhook,scheduled'],
            'definition.timeoutMs' => ['required', 'integer', 'min:1000', 'max:3600000'],
            'definition.retryPolicy' => ['required', 'array'],
            'definition.retryPolicy.maxAttempts' => ['required', 'integer', 'min:1', 'max:10'],
            'definition.retryPolicy.backoff' => ['required', 'in:exponential'],
            'definition.steps' => ['required', 'array', 'min:1', 'max:50'],
            'definition.steps.*.id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]+$/', 'max:100'],
            'definition.steps.*.label' => ['required', 'string', 'max:150'],
            'definition.steps.*.type' => ['required', 'in:http,delay,condition,script,approval'],
            'definition.steps.*.dependsOn' => ['nullable', 'array'],
            'definition.steps.*.dependsOn.*' => ['string', 'max:100'],
            'definition.steps.*.config' => ['nullable', 'array'],
        ];
    }
}
