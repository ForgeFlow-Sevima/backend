<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkflowRunResource;
use App\Jobs\ExecuteWorkflowRunJob;
use App\Models\WebhookTrigger;
use App\Models\Workflow;
use App\Services\Workflow\AuditLogger;
use App\Services\Workflow\WorkflowExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class WebhookIncomingController extends Controller
{
    public function __construct(
        private readonly WorkflowExecutionService $executionService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function store(Request $request, Workflow $workflow): WorkflowRunResource|JsonResponse
    {
        $workflow->load(['currentVersion', 'webhookTriggers']);

        if ($workflow->status !== 'active') {
            return response()->json(['message' => 'Workflow is not active.'], 422);
        }

        if (! $workflow->currentVersion) {
            return response()->json(['message' => 'Workflow has no active version.'], 422);
        }

        if (($workflow->currentVersion->definition['trigger'] ?? null) !== 'webhook') {
            return response()->json(['message' => 'Workflow is not configured for webhook trigger.'], 422);
        }

        $matchedTrigger = $this->matchedTrigger($request, $workflow);
        if ($matchedTrigger === false) {
            return response()->json(['message' => 'Invalid webhook secret.'], 403);
        }

        $run = $this->executionService->createRun($workflow, null, [
            'body' => $request->all(),
            'headers' => $this->safeHeaders($request),
            'query' => $request->query(),
        ], 'webhook');

        if ($matchedTrigger instanceof WebhookTrigger) {
            $matchedTrigger->forceFill(['last_triggered_at' => now()])->save();
        }

        $this->auditLogger->log('run.started', $run, null, [], [
            'workflowId' => $workflow->id,
            'trigger' => 'webhook',
            'webhookTriggerId' => $matchedTrigger instanceof WebhookTrigger ? $matchedTrigger->id : null,
        ], $request);

        ExecuteWorkflowRunJob::dispatch($run->id);

        return new WorkflowRunResource($run->load(['workflow', 'stepRuns', 'executionLogs']));
    }

    private function matchedTrigger(Request $request, Workflow $workflow): WebhookTrigger|bool|null
    {
        $activeTriggers = $workflow->webhookTriggers->where('is_active', true);
        if ($activeTriggers->isEmpty()) {
            return null;
        }

        $secret = $request->header('X-FlowForge-Webhook-Secret') ?? $request->query('secret');
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        return $activeTriggers->first(fn (WebhookTrigger $trigger) => Hash::check($secret, $trigger->secret_hash)) ?: false;
    }

    private function safeHeaders(Request $request): array
    {
        return collect($request->headers->all())
            ->except(['authorization', 'cookie', 'x-flowforge-webhook-secret'])
            ->map(fn (array $values) => $values[0] ?? null)
            ->all();
    }
}
