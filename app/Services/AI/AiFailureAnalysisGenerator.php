<?php

namespace App\Services\AI;

use App\AI\FailureAnalysisAgent;
use App\Models\WorkflowRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class AiFailureAnalysisGenerator
{
    public const PROMPT_VERSION = 'failure-analysis-v1';

    public function generate(WorkflowRun $run): FailureAnalysisGenerationResult
    {
        if ((string) config('flowforge_ai.provider') === 'gemini' && blank(config('ai.providers.gemini.key'))) {
            throw ValidationException::withMessages([
                'ai' => 'Gemini API key is not configured. Set GEMINI_API_KEY in backend .env.',
            ]);
        }

        $run->loadMissing([
            'workflow.currentVersion',
            'workflowVersion',
            'stepRuns' => fn ($query) => $query->orderBy('created_at'),
            'executionLogs.stepRun',
            'approvals',
        ]);

        $context = $this->buildContext($run);
        $prompt = $this->buildPrompt($context);
        $agent = new FailureAnalysisAgent();
        $response = $this->promptAgent($agent, $prompt);
        $analysis = $this->normalizeAnalysis($response->toArray(), $context);

        return new FailureAnalysisGenerationResult(
            $analysis,
            $prompt,
            $context,
            $this->rawResponse($response),
            $this->usage($response),
        );
    }

    public function buildContext(WorkflowRun $run): array
    {
        $failedStep = $run->stepRuns->first(fn ($stepRun) => $stepRun->status === 'failed')
            ?? $run->stepRuns->first(fn ($stepRun) => in_array($stepRun->status, ['running', 'retrying', 'waiting_approval'], true))
            ?? $run->stepRuns->first();

        $definition = $run->workflowVersion?->definition
            ?? $run->workflow?->currentVersion?->definition
            ?? [];

        $logLimit = (int) config('flowforge_ai.failure_log_limit', 100);
        $logs = $run->executionLogs
            ->sortByDesc('created_at')
            ->take($logLimit)
            ->reverse()
            ->values()
            ->map(fn ($log): array => [
                'id' => $log->id,
                'level' => $log->level,
                'message' => $log->message,
                'stepId' => $log->stepRun?->step_id,
                'metadata' => $log->metadata ?? [],
                'timestamp' => $log->created_at?->toIso8601String(),
            ])
            ->all();

        return [
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'triggerType' => $run->trigger_type,
                'errorMessage' => $run->error_message,
                'durationMs' => $run->duration_ms,
                'inputPayload' => $run->input_payload,
                'outputPayload' => $run->output_payload,
                'startedAt' => $run->started_at?->toIso8601String(),
                'finishedAt' => $run->finished_at?->toIso8601String(),
            ],
            'workflow' => [
                'id' => $run->workflow?->id,
                'name' => $run->workflow?->name,
                'definition' => $definition,
            ],
            'failedStep' => $failedStep ? $this->stepContext($failedStep, $definition) : null,
            'steps' => $run->stepRuns->map(fn ($stepRun) => $this->stepContext($stepRun, $definition))->values()->all(),
            'logs' => $logs,
            'approvals' => $run->approvals->map(fn ($approval): array => [
                'id' => $approval->id,
                'stepRunId' => $approval->step_run_id,
                'status' => $approval->status,
                'decision' => $approval->decision,
                'note' => $approval->note,
            ])->values()->all(),
        ];
    }

    private function stepContext($stepRun, array $definition): array
    {
        $definitionStep = collect($definition['steps'] ?? [])->firstWhere('id', $stepRun->step_id) ?? [];

        return [
            'id' => $stepRun->id,
            'stepId' => $stepRun->step_id,
            'name' => $stepRun->step_name,
            'type' => $stepRun->step_type,
            'status' => $stepRun->status,
            'dependsOn' => $stepRun->depends_on ?? [],
            'attemptCount' => $stepRun->attempt_count,
            'maxRetries' => $stepRun->max_retries,
            'errorMessage' => $stepRun->error_message,
            'durationMs' => $stepRun->duration_ms,
            'inputPayload' => $stepRun->input_payload,
            'outputPayload' => $stepRun->output_payload,
            'definitionConfig' => Arr::get($definitionStep, 'config', []),
        ];
    }

    private function buildPrompt(array $context): string
    {
        $contextJson = $this->json($context);

        return <<<PROMPT
Analyze this failed ForgeFlow run using only the JSON context below.

Instructions:
- Identify the most likely root cause only if direct evidence supports it.
- Cite concrete evidence in the evidence array: error messages, failed step id, log messages, config fields, or status transitions.
- If no concrete error/log/config evidence exists, choose category unknown, confidence low, retryRecommended false unless timeout evidence exists.
- suggestedFix must be a concrete action tied to the evidence.

Context JSON:
{$contextJson}
PROMPT;
    }

    private function promptAgent(FailureAnalysisAgent $agent, string $prompt): StructuredAgentResponse
    {
        try {
            $response = $agent->prompt(
                $prompt,
                provider: config('flowforge_ai.provider', 'gemini'),
                model: config('flowforge_ai.model', 'gemini-2.5-flash'),
                timeout: (int) config('flowforge_ai.timeout', 300),
            );
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'ai' => 'AI provider request failed: '.$exception->getMessage(),
            ]);
        }

        if (! $response instanceof StructuredAgentResponse) {
            throw ValidationException::withMessages(['ai' => 'AI provider did not return structured JSON.']);
        }

        return $response;
    }

    private function normalizeAnalysis(array $analysis, array $context): array
    {
        $category = $analysis['category'] ?? 'unknown';
        $confidence = $analysis['confidence'] ?? 'low';
        $affectedStepId = $analysis['affectedStepId'] ?? data_get($context, 'failedStep.stepId');

        if (! in_array($category, ['configuration', 'network', 'authentication', 'code', 'timeout', 'unknown'], true)) {
            $category = 'unknown';
        }

        if (! in_array($confidence, ['low', 'medium', 'high'], true)) {
            $confidence = 'low';
        }

        return [
            'summary' => trim((string) ($analysis['summary'] ?? $analysis['rootCause'] ?? 'Insufficient evidence to summarize the failure.')),
            'rootCause' => trim((string) ($analysis['rootCause'] ?? 'Insufficient evidence to determine a root cause from the supplied run context.')),
            'suggestedFix' => trim((string) ($analysis['suggestedFix'] ?? 'Collect more logs for the failed step, verify the step configuration, then rerun.')),
            'affectedStepId' => is_string($affectedStepId) && $affectedStepId !== '' ? $affectedStepId : null,
            'category' => $category,
            'retryRecommended' => (bool) ($analysis['retryRecommended'] ?? false),
            'confidence' => $confidence,
            'evidence' => array_values(array_filter(array_map('strval', Arr::wrap($analysis['evidence'] ?? [])))),
        ];
    }

    private function rawResponse(StructuredAgentResponse $response): array
    {
        return [
            'mode' => 'llm',
            'text' => Str::limit($response->text, 2000),
            'structured' => $response->toArray(),
            'usage' => $this->usage($response),
        ];
    }

    private function usage(StructuredAgentResponse $response): array
    {
        $promptTokens = $response->usage->promptTokens;
        $completionTokens = $response->usage->completionTokens;

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
        ];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }
}
