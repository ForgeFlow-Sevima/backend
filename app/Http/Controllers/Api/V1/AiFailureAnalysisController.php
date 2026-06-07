<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiFailureAnalysisResource;
use App\Models\WorkflowRun;
use App\Services\AI\AiFailureAnalysisGenerator;
use App\Services\AI\AiRuntimeConfig;
use App\Services\Workflow\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiFailureAnalysisController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly AiFailureAnalysisGenerator $generator,
        private readonly AiRuntimeConfig $aiConfig,
    ) {}

    public function show(Request $request, WorkflowRun $run): AiFailureAnalysisResource
    {
        $this->abortUnlessTenant($request, $run);
        $analysis = $run->aiFailureAnalyses()->with('stepRun')->latest()->firstOrFail();

        return new AiFailureAnalysisResource($analysis);
    }

    public function store(Request $request, WorkflowRun $run): AiFailureAnalysisResource|JsonResponse
    {
        $this->abortUnlessTenant($request, $run);

        if (! in_array($run->status, ['failed', 'timeout'], true)) {
            return response()->json(['message' => 'AI analysis is only available for failed or timed out runs.'], 422);
        }

        $result = $this->generator->generate($run);
        $affectedStepRun = $run->stepRuns()->where('step_id', $result->analysis['affectedStepId'])->first();

        $analysis = $run->aiFailureAnalyses()->create([
            'tenant_id' => $request->user()->tenant_id,
            'step_run_id' => $affectedStepRun?->id,
            'requested_by_user_id' => $request->user()->id,
            'provider' => $this->aiConfig->provider(),
            'model' => $this->aiConfig->model(),
            'prompt_version' => AiFailureAnalysisGenerator::PROMPT_VERSION,
            'prompt_text' => $result->promptText,
            'input_context' => $result->inputContext,
            'summary' => $result->analysis['summary'],
            'root_cause' => $result->analysis['rootCause'],
            'suggested_fix' => $result->analysis['suggestedFix'],
            'category' => $result->analysis['category'],
            'retry_recommended' => $result->analysis['retryRecommended'],
            'confidence' => $result->analysis['confidence'],
            'raw_response' => $result->rawResponse,
            'prompt_tokens' => $result->usage['prompt_tokens'],
            'completion_tokens' => $result->usage['completion_tokens'],
            'total_tokens' => $result->usage['total_tokens'],
        ]);

        $this->auditLogger->log('ai.analysis.created', $analysis, $request->user(), [], [
            'runId' => $run->id,
            'stepRunId' => $affectedStepRun?->id,
        ], $request);

        return new AiFailureAnalysisResource($analysis->load('stepRun'));
    }

    private function abortUnlessTenant(Request $request, WorkflowRun $run): void
    {
        abort_unless($run->tenant_id === $request->user()->tenant_id, 404);
    }
}
