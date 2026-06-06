<?php

namespace App\Services\Workflow;

use App\Models\StepRun;
use App\Models\Workflow;
use App\Models\WorkflowApproval;
use App\Models\WorkflowRun;
use App\Jobs\ExecuteWorkflowRunJob;
use App\Jobs\ExecuteWorkflowStepJob;
use App\Services\Workflow\StepHandlers\ConditionStepHandler;
use App\Services\Workflow\StepHandlers\DelayStepHandler;
use App\Services\Workflow\StepHandlers\HttpStepHandler;
use App\Services\Workflow\StepHandlers\ScriptStepHandler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkflowExecutionService
{
    public function __construct(
        private readonly WorkflowDefinitionValidator $validator,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function createRun(Workflow $workflow, ?string $userId, array $input = [], string $triggerType = 'manual'): WorkflowRun
    {
        $workflow->load('currentVersion');
        abort_unless($workflow->currentVersion, 422, 'Workflow has no active version.');

        return DB::transaction(function () use ($workflow, $userId, $input, $triggerType): WorkflowRun {
            $definition = $workflow->currentVersion->definition;
            $maxAttempts = (int) ($definition['retryPolicy']['maxAttempts'] ?? 1);

            $run = $workflow->runs()->create([
                'tenant_id' => $workflow->tenant_id,
                'workflow_version_id' => $workflow->current_version_id,
                'triggered_by_user_id' => $userId,
                'status' => 'pending',
                'trigger_type' => $triggerType,
                'input_payload' => $input,
            ]);

            foreach ($definition['steps'] ?? [] as $step) {
                $run->stepRuns()->create([
                    'tenant_id' => $workflow->tenant_id,
                    'step_id' => $step['id'],
                    'step_name' => $step['label'],
                    'step_type' => $step['type'],
                    'status' => 'pending',
                    'depends_on' => $step['dependsOn'] ?? [],
                    'max_retries' => max(0, $maxAttempts - 1),
                ]);
            }

            $this->log($run, null, 'info', 'Workflow run queued.', ['triggeredBy' => $userId]);
            $workflow->forceFill(['last_run_at' => now()])->save();

            return $run->load(['workflow', 'stepRuns', 'executionLogs']);
        });
    }

    public function execute(WorkflowRun $run): void
    {
        $run->load(['workflow.currentVersion', 'stepRuns']);
        $definition = $run->workflowVersion?->definition ?? $run->workflow?->currentVersion?->definition ?? [];
        $order = $this->validator->topologicalOrder($definition);
        $stepsById = collect($definition['steps'] ?? [])->keyBy('id');
        $startedAt = CarbonImmutable::now();
        $timeoutMs = (int) ($definition['timeoutMs'] ?? 60000);
        if ($run->status === 'pending') {
            $run->forceFill(['status' => 'running', 'started_at' => $startedAt])->save();
            $this->log($run, null, 'info', 'Workflow run started.');
        }

        try {
            if ($startedAt->diffInMilliseconds(now()) > $timeoutMs) {
                throw new WorkflowTimeoutException('Workflow global timeout exceeded.');
            }

            $this->applyBranchSkips($run, $definition);
            $run->load('stepRuns');

            if ($run->stepRuns->contains(fn (StepRun $stepRun) => in_array($stepRun->status, ['running', 'retrying', 'waiting_approval'], true))) {
                return;
            }

            if ($run->stepRuns->contains(fn (StepRun $stepRun) => $stepRun->status === 'failed')) {
                $this->failRun($run, 'One or more workflow steps failed.');
                return;
            }

            $readyStepIds = $this->readyStepIds($run, $order);
            if ($readyStepIds !== []) {
                foreach ($readyStepIds as $stepId) {
                    ExecuteWorkflowStepJob::dispatch($run->id, $stepId);
                }

                return;
            }

            if ($run->stepRuns->every(fn (StepRun $stepRun) => in_array($stepRun->status, ['success', 'skipped'], true))) {
                $this->finishRun($run);
                return;
            }

            $this->failRun($run, 'No runnable workflow steps remain.');
        } catch (WorkflowTimeoutException $exception) {
            $finishedAt = now();
            $run->forceFill([
                'status' => 'timeout',
                'error_message' => $exception->getMessage(),
                'finished_at' => $finishedAt,
                'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
            ])->save();
            $this->log($run, null, 'error', $exception->getMessage());
            $this->auditLogger->log('run.finished', $run, $run->triggeredBy, [], ['status' => 'timeout']);
        } catch (Throwable $exception) {
            $this->failRun($run, $exception->getMessage());
        }
    }

    public function executeSingleStep(string $runId, string $stepId): void
    {
        $run = WorkflowRun::query()->with(['workflow.currentVersion', 'workflowVersion', 'stepRuns', 'triggeredBy'])->findOrFail($runId);
        if (! in_array($run->status, ['pending', 'running'], true)) {
            return;
        }

        $definition = $run->workflowVersion?->definition ?? $run->workflow?->currentVersion?->definition ?? [];
        $step = collect($definition['steps'] ?? [])->firstWhere('id', $stepId);
        $stepRun = $run->stepRuns->firstWhere('step_id', $stepId);
        if (! $step || ! $stepRun || $stepRun->status !== 'pending') {
            return;
        }

        if (($step['type'] ?? null) === 'approval') {
            $this->createApproval($run, $stepRun, $step, $this->contextFor($run));
            return;
        }

        try {
            $this->executeStep($run, $stepRun, $step, $this->contextFor($run));
        } catch (Throwable $exception) {
            $this->failRun($run, $exception->getMessage());
        }
    }

    public function resumeAfterApproval(WorkflowApproval $approval, string $decision, ?string $note, ?string $userId): WorkflowRun
    {
        return DB::transaction(function () use ($approval, $decision, $note, $userId): WorkflowRun {
            $approval->load(['workflowRun', 'stepRun']);
            abort_unless($approval->status === 'pending', 422, 'Approval has already been decided.');

            $status = $decision === 'approved' ? 'approved' : 'rejected';
            $approval->forceFill([
                'status' => $status,
                'decided_by_user_id' => $userId,
                'decision_note' => $note,
                'decided_at' => now(),
            ])->save();

            if ($decision === 'approved') {
                $approval->stepRun->forceFill([
                    'status' => 'success',
                    'output_payload' => ['approved' => true, 'note' => $note],
                    'finished_at' => now(),
                ])->save();

                $approval->workflowRun->forceFill(['status' => 'running'])->save();
                $this->log($approval->workflowRun, $approval->stepRun, 'info', 'Approval accepted.', ['approvalId' => $approval->id]);
                ExecuteWorkflowRunJob::dispatch($approval->workflowRun->id);
            } else {
                $approval->stepRun->forceFill(['status' => 'failed', 'error_message' => 'Approval rejected.', 'finished_at' => now()])->save();
                $this->failRun($approval->workflowRun, 'Approval rejected.');
            }

            return $approval->workflowRun->fresh(['workflow', 'stepRuns', 'executionLogs', 'approvals']);
        });
    }

    private function executeStep(WorkflowRun $run, StepRun $stepRun, array $step, array $context): array
    {
        $maxAttempts = $stepRun->max_retries + 1;
        $lastError = null;

        for ($attemptNumber = 1; $attemptNumber <= $maxAttempts; $attemptNumber++) {
            $startedAt = CarbonImmutable::now();
            $stepRun->forceFill([
                'status' => 'running',
                'started_at' => $stepRun->started_at ?? $startedAt,
                'attempt_count' => $attemptNumber,
                'input_payload' => $context,
            ])->save();
            $this->log($run, $stepRun, 'info', "Step [{$stepRun->step_id}] attempt $attemptNumber started.");

            $attempt = $stepRun->attempts()->create([
                'tenant_id' => $run->tenant_id,
                'workflow_run_id' => $run->id,
                'attempt_number' => $attemptNumber,
                'status' => 'running',
                'input_payload' => $context,
                'started_at' => $startedAt,
            ]);

            try {
                $output = $this->handlerFor($step['type'])->handle($step, $context);
                $finishedAt = now();

                $attempt->forceFill([
                    'status' => 'success',
                    'output_payload' => $output,
                    'finished_at' => $finishedAt,
                    'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
                ])->save();

                $stepRun->forceFill([
                    'status' => 'success',
                    'output_payload' => $output,
                    'finished_at' => $finishedAt,
                    'duration_ms' => (int) CarbonImmutable::parse($stepRun->started_at)->diffInMilliseconds($finishedAt),
                    'error_message' => null,
                ])->save();
                $this->log($run, $stepRun, 'info', "Step [{$stepRun->step_id}] succeeded.", ['attempt' => $attemptNumber]);

                return $output;
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
                $finishedAt = now();
                $attempt->forceFill([
                    'status' => 'failed',
                    'error_message' => $lastError,
                    'finished_at' => $finishedAt,
                    'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
                ])->save();

                if ($attemptNumber < $maxAttempts) {
                    $backoff = min(30, 2 ** ($attemptNumber - 1));
                    $stepRun->forceFill(['status' => 'retrying', 'backoff_seconds' => $backoff, 'error_message' => $lastError])->save();
                    $this->log($run, $stepRun, 'warning', "Step [{$stepRun->step_id}] retrying.", ['attempt' => $attemptNumber, 'backoffSeconds' => $backoff]);
                    sleep($backoff);
                }
            }
        }

        $finishedAt = now();
        $stepRun->forceFill([
            'status' => 'failed',
            'error_message' => $lastError,
            'finished_at' => $finishedAt,
            'duration_ms' => (int) CarbonImmutable::parse($stepRun->started_at)->diffInMilliseconds($finishedAt),
        ])->save();
        $this->log($run, $stepRun, 'error', "Step [{$stepRun->step_id}] failed.", ['error' => $lastError]);
        $this->auditLogger->log('step.failed', $stepRun, $run->triggeredBy, [], ['error' => $lastError]);

        throw new StepFailedException($lastError ?: 'Step failed.');
    }

    private function readyStepIds(WorkflowRun $run, array $order): array
    {
        $byId = $run->stepRuns->keyBy('step_id');

        return collect($order)
            ->filter(function (string $stepId) use ($byId): bool {
                $stepRun = $byId->get($stepId);
                if (! $stepRun || $stepRun->status !== 'pending') {
                    return false;
                }

                return collect($stepRun->depends_on ?? [])->every(function (string $dependency) use ($byId): bool {
                    return in_array($byId->get($dependency)?->status, ['success', 'skipped'], true);
                });
            })
            ->values()
            ->all();
    }

    private function contextFor(WorkflowRun $run): array
    {
        $run->load('stepRuns');

        return [
            'input' => $run->input_payload ?? [],
            'run' => ['id' => $run->id, 'trigger' => $run->trigger_type],
            'steps' => $run->stepRuns
                ->whereIn('status', ['success', 'skipped'])
                ->mapWithKeys(fn (StepRun $stepRun) => [$stepRun->step_id => $stepRun->output_payload ?? []])
                ->all(),
        ];
    }

    private function applyBranchSkips(WorkflowRun $run, array $definition): void
    {
        $stepsById = collect($definition['steps'] ?? [])->keyBy('id');
        $children = collect($definition['steps'] ?? [])->flatMap(function (array $step) {
            return collect($step['dependsOn'] ?? [])->map(fn (string $dependency) => [$dependency, $step['id']]);
        })->groupBy(0)->map(fn ($items) => $items->pluck(1)->values()->all())->all();

        foreach ($run->stepRuns->where('step_type', 'condition')->where('status', 'success') as $conditionRun) {
            $condition = $stepsById->get($conditionRun->step_id);
            $config = $condition['config'] ?? [];
            $selected = collect($conditionRun->output_payload['selectedStepIds'] ?? [])->filter()->values();
            $targets = collect($config['onTrue'] ?? [])->merge($config['onFalse'] ?? [])->filter()->unique()->values();

            foreach ($targets->diff($selected) as $stepId) {
                $this->skipStepAndExclusiveChildren($run, (string) $stepId, $children);
            }
        }
    }

    private function skipStepAndExclusiveChildren(WorkflowRun $run, string $stepId, array $children): void
    {
        $run->load('stepRuns');
        $stepRun = $run->stepRuns->firstWhere('step_id', $stepId);
        if (! $stepRun || $stepRun->status !== 'pending') {
            return;
        }

        $stepRun->forceFill(['status' => 'skipped', 'finished_at' => now(), 'output_payload' => ['skipped' => true]])->save();
        $this->log($run, $stepRun, 'info', "Step [{$stepId}] skipped by branch condition.");

        $run->load('stepRuns');
        $byId = $run->stepRuns->keyBy('step_id');
        foreach ($children[$stepId] ?? [] as $childId) {
            $child = $byId->get($childId);
            if ($child && collect($child->depends_on ?? [])->every(fn (string $dependency) => $byId->get($dependency)?->status === 'skipped')) {
                $this->skipStepAndExclusiveChildren($run, $childId, $children);
            }
        }
    }

    private function createApproval(WorkflowRun $run, StepRun $stepRun, array $step, array $context): void
    {
        $config = app(WorkflowContextResolver::class)->resolveConfig($step['config'] ?? [], $context);
        $approval = WorkflowApproval::query()->firstOrCreate(
            ['workflow_run_id' => $run->id, 'step_run_id' => $stepRun->id, 'status' => 'pending'],
            [
                'tenant_id' => $run->tenant_id,
                'title' => (string) ($config['title'] ?? $stepRun->step_name ?? 'Approval required'),
                'description' => $config['description'] ?? null,
                'approvers' => is_array($config['approvers'] ?? null) ? $config['approvers'] : ['admin', 'editor'],
                'expires_at' => isset($config['timeoutMs']) ? now()->addMilliseconds((int) $config['timeoutMs']) : null,
            ],
        );

        $stepRun->forceFill(['status' => 'waiting_approval', 'started_at' => $stepRun->started_at ?? now(), 'input_payload' => $context])->save();
        $run->forceFill(['status' => 'waiting_approval'])->save();
        $this->log($run, $stepRun, 'info', 'Workflow is waiting for human approval.', ['approvalId' => $approval->id]);
    }

    private function finishRun(WorkflowRun $run): void
    {
        $run->load('stepRuns');
        $startedAt = $run->started_at ? CarbonImmutable::parse($run->started_at) : CarbonImmutable::now();
        $finishedAt = now();
        $outputs = $run->stepRuns->mapWithKeys(fn (StepRun $stepRun) => [$stepRun->step_id => $stepRun->output_payload])->all();

        $run->forceFill([
            'status' => 'success',
            'output_payload' => ['steps' => $outputs],
            'finished_at' => $finishedAt,
            'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
        ])->save();
        $this->log($run, null, 'info', 'Workflow run finished.', ['status' => 'success']);
        $this->auditLogger->log('run.finished', $run, $run->triggeredBy, [], ['status' => 'success']);
    }

    private function handlerFor(string $type): StepHandlers\StepHandler
    {
        return match ($type) {
            'http' => new HttpStepHandler(),
            'delay' => new DelayStepHandler(),
            'condition' => new ConditionStepHandler(),
            'script' => new ScriptStepHandler(),
            'approval' => throw new \LogicException('Approval step is handled by the execution coordinator.'),
        };
    }

    private function failRun(WorkflowRun $run, string $message): void
    {
        $startedAt = $run->started_at ? CarbonImmutable::parse($run->started_at) : CarbonImmutable::now();
        $finishedAt = now();
        $run->forceFill([
            'status' => 'failed',
            'error_message' => $message,
            'finished_at' => $finishedAt,
            'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
        ])->save();
        $this->log($run, null, 'error', 'Workflow run failed.', ['error' => $message]);
        $this->auditLogger->log('run.finished', $run, $run->triggeredBy, [], ['status' => 'failed', 'error' => $message]);
    }

    private function log(WorkflowRun $run, ?StepRun $stepRun, string $level, string $message, array $metadata = []): void
    {
        $run->executionLogs()->create([
            'tenant_id' => $run->tenant_id,
            'step_run_id' => $stepRun?->id,
            'level' => $level,
            'message' => $message,
            'metadata' => $metadata ?: null,
        ]);
    }
}

class StepFailedException extends \RuntimeException {}

class WorkflowTimeoutException extends \RuntimeException {}
