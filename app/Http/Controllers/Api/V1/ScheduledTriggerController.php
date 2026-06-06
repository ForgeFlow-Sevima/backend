<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkflowRunResource;
use App\Models\ScheduledTrigger;
use App\Models\Workflow;
use App\Services\Workflow\ScheduledTriggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduledTriggerController extends Controller
{
    public function __construct(private readonly ScheduledTriggerService $service) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workflowId' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:150'],
            'cronExpression' => ['required', 'string', 'max:100'],
            'timezone' => ['nullable', 'string', 'max:100'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        $workflow = Workflow::query()->where('tenant_id', $request->user()->tenant_id)->findOrFail($data['workflowId']);
        $trigger = ScheduledTrigger::query()->create([
            'tenant_id' => $request->user()->tenant_id,
            'workflow_id' => $workflow->id,
            'created_by' => $request->user()->id,
            'name' => $data['name'],
            'cron_expression' => $data['cronExpression'],
            'timezone' => $data['timezone'] ?? 'Asia/Jakarta',
            'is_active' => $data['isActive'] ?? true,
            'next_run_at' => $this->service->nextRunAt($data['cronExpression'], $data['timezone'] ?? 'Asia/Jakarta'),
        ]);

        return response()->json(['data' => $this->serialize($trigger)], 201);
    }

    public function update(Request $request, ScheduledTrigger $trigger): JsonResponse
    {
        $this->abortUnlessTenant($request, $trigger);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'cronExpression' => ['sometimes', 'string', 'max:100'],
            'timezone' => ['sometimes', 'string', 'max:100'],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        $cron = $data['cronExpression'] ?? $trigger->cron_expression;
        $timezone = $data['timezone'] ?? $trigger->timezone;
        $trigger->fill([
            'name' => $data['name'] ?? $trigger->name,
            'cron_expression' => $cron,
            'timezone' => $timezone,
            'is_active' => $data['isActive'] ?? $trigger->is_active,
            'next_run_at' => $this->service->nextRunAt($cron, $timezone),
        ])->save();

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
}
