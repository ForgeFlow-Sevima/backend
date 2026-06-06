<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkflowRunResource;
use App\Models\ScheduledTrigger;
use App\Models\Workflow;
use App\Services\Workflow\AuditLogger;
use App\Services\Workflow\ScheduledTriggerService;
use Cron\CronExpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduledTriggerController extends Controller
{
    public function __construct(
        private readonly ScheduledTriggerService $service,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'active' => ['nullable', 'boolean'],
        ]);

        $query = ScheduledTrigger::query()
            ->with(['workflow', 'workflow.runs' => fn ($query) => $query->where('trigger_type', 'scheduled')->latest()->limit(1)])
            ->where('tenant_id', $request->user()->tenant_id)
            ->latest();

        if ($request->boolean('active')) {
            $query->where('is_active', true);
        }

        $triggers = $query->get();

        return response()->json(['data' => $triggers->map(fn (ScheduledTrigger $trigger) => [
            ...$this->serialize($trigger),
            'workflowName' => $trigger->workflow?->name,
            'lastRun' => $trigger->workflow?->runs->first() ? [
                'id' => $trigger->workflow->runs->first()->id,
                'status' => $trigger->workflow->runs->first()->status,
                'startedAt' => $trigger->workflow->runs->first()->started_at?->toIso8601String() ?? $trigger->workflow->runs->first()->created_at?->toIso8601String(),
            ] : null,
        ])->values()]);
    }

    public function indexForWorkflow(Request $request, Workflow $workflow): JsonResponse
    {
        abort_unless($workflow->tenant_id === $request->user()->tenant_id, 404);

        $triggers = ScheduledTrigger::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('workflow_id', $workflow->id)
            ->latest()
            ->get();

        return response()->json(['data' => $triggers->map(fn (ScheduledTrigger $trigger) => $this->serialize($trigger))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workflowId' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:150'],
            'cronExpression' => ['required', 'string', 'max:100', $this->cronRule()],
            'timezone' => ['nullable', 'string', 'max:100', 'timezone'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        $timezone = $data['timezone'] ?? config('app.timezone');
        $workflow = Workflow::query()->where('tenant_id', $request->user()->tenant_id)->findOrFail($data['workflowId']);
        $trigger = ScheduledTrigger::query()->create([
            'tenant_id' => $request->user()->tenant_id,
            'workflow_id' => $workflow->id,
            'created_by' => $request->user()->id,
            'name' => $data['name'],
            'cron_expression' => $data['cronExpression'],
            'timezone' => $timezone,
            'is_active' => $data['isActive'] ?? true,
            'next_run_at' => $this->service->nextRunAt($data['cronExpression'], $timezone),
        ]);
        $this->auditLogger->log('scheduled_trigger.created', $trigger, $request->user(), [], $this->serialize($trigger), $request);

        return response()->json(['data' => $this->serialize($trigger)], 201);
    }

    public function update(Request $request, ScheduledTrigger $trigger): JsonResponse
    {
        $this->abortUnlessTenant($request, $trigger);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'cronExpression' => ['sometimes', 'string', 'max:100', $this->cronRule()],
            'timezone' => ['sometimes', 'string', 'max:100', 'timezone'],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        $oldValues = $this->serialize($trigger);
        $cron = $data['cronExpression'] ?? $trigger->cron_expression;
        $timezone = $data['timezone'] ?? $trigger->timezone;
        $isActive = $data['isActive'] ?? $trigger->is_active;
        $trigger->fill([
            'name' => $data['name'] ?? $trigger->name,
            'cron_expression' => $cron,
            'timezone' => $timezone,
            'is_active' => $isActive,
            'next_run_at' => $isActive ? $this->service->nextRunAt($cron, $timezone) : null,
        ])->save();
        $action = array_key_exists('isActive', $data)
            ? ($isActive ? 'scheduled_trigger.resumed' : 'scheduled_trigger.paused')
            : 'scheduled_trigger.updated';
        $this->auditLogger->log($action, $trigger, $request->user(), $oldValues, $this->serialize($trigger), $request);

        return response()->json(['data' => $this->serialize($trigger)]);
    }

    public function runNow(Request $request, ScheduledTrigger $trigger): WorkflowRunResource
    {
        $this->abortUnlessTenant($request, $trigger);

        return new WorkflowRunResource($this->service->trigger($trigger, ['manualScheduleRun' => true])->load(['workflow', 'stepRuns', 'executionLogs']));
    }

    private function abortUnlessTenant(Request $request, ScheduledTrigger $trigger): void
    {
        abort_unless($trigger->tenant_id === $request->user()->tenant_id, 404);
    }

    private function serialize(ScheduledTrigger $trigger): array
    {
        return [
            'id' => $trigger->id,
            'workflowId' => $trigger->workflow_id,
            'name' => $trigger->name,
            'cronExpression' => $trigger->cron_expression,
            'timezone' => $trigger->timezone,
            'isActive' => $trigger->is_active,
            'lastRunAt' => $trigger->last_run_at?->toIso8601String(),
            'nextRunAt' => $trigger->next_run_at?->toIso8601String(),
        ];
    }

    private function cronRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || ! CronExpression::isValidExpression($value)) {
                $fail('The '.$attribute.' field must be a valid cron expression.');
            }
        };
    }
}
