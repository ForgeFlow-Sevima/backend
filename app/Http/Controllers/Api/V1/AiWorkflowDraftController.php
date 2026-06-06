<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AiWorkflowDraftRequest;
use App\Models\AiWorkflowGeneration;
use App\Services\AI\WorkflowDraftGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AiWorkflowDraftController extends Controller
{
    public function store(AiWorkflowDraftRequest $request, WorkflowDraftGenerator $generator): JsonResponse
    {
        set_time_limit((int) config('flowforge_ai.timeout', 300));

        $prompt = $request->validated('prompt');
        $context = $request->validated('context') ?? [];

        try {
            $result = $generator->generate($prompt, $context);
        } catch (ValidationException $exception) {
            AiWorkflowGeneration::query()->create([
                'tenant_id' => $request->user()->tenant_id,
                'requested_by_user_id' => $request->user()->id,
                'provider' => config('flowforge_ai.provider', 'gemini'),
                'model' => config('flowforge_ai.model', 'gemini-2.5-flash'),
                'user_prompt' => $prompt,
                'prompt_version' => WorkflowDraftGenerator::PROMPT_VERSION,
                'validation_errors' => $exception->errors(),
                'status' => 'failed',
                'raw_response' => ['mode' => 'llm', 'error' => $exception->getMessage()],
            ]);

            throw $exception;
        }

        AiWorkflowGeneration::query()->create([
            'tenant_id' => $request->user()->tenant_id,
            'requested_by_user_id' => $request->user()->id,
            'provider' => config('flowforge_ai.provider', 'gemini'),
            'model' => config('flowforge_ai.model', 'gemini-2.5-flash'),
            'user_prompt' => $prompt,
            'prompt_version' => WorkflowDraftGenerator::PROMPT_VERSION,
            'system_prompt' => 'ForgeFlow workflow draft structured JSON agent.',
            'generated_definition' => $result->definition,
            'status' => 'success',
            'raw_response' => [
                'mode' => 'llm',
                'truncated' => $result->truncated,
                'repair_attempts' => $result->repairAttempts,
                'provider_response' => $result->rawResponse,
            ],
            'prompt_tokens' => $result->usage['prompt_tokens'],
            'completion_tokens' => $result->usage['completion_tokens'],
            'total_tokens' => $result->usage['total_tokens'],
        ]);

        return response()->json(['data' => $result->definition]);
    }
}
