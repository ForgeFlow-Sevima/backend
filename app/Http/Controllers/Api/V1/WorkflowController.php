<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\ExecuteWorkflowRunJob;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ValidateWorkflowDefinitionRequest;
use App\Http\Requests\Api\V1\TriggerWorkflowRunRequest;
use App\Http\Requests\Api\V1\WorkflowUpsertRequest;
use App\Http\Resources\ApiStatus;
use App\Http\Resources\WorkflowResource;
use App\Http\Resources\WorkflowRunResource;
use App\Models\Workflow;
use App\Services\Workflow\AuditLogger;
use App\Services\Workflow\WorkflowDefinitionValidator;
use App\Services\Workflow\WorkflowExecutionService;
use App\Services\Workflow\WorkflowVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowVersionService $versionService,
        private readonly WorkflowDefinitionValidator $definitionValidator,
        private readonly WorkflowExecutionService $executionService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = min(max((int) $request->integer('perPage', 15), 1), 100);
        $query = Workflow::query()
            ->with(['currentVersion.workflow', 'currentVersion.creator', 'runs' => fn ($query) => $query->latest()->limit(50)])
            ->where('tenant_id', $request->user()->tenant_id)
            ->latest();

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%"));
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', ApiStatus::workflowToDatabase($request->string('status')->toString()));
        }

        if ($request->filled('trigger') && $request->trigger !== 'all') {
            $query->whereHas('currentVersion', fn ($query) => $query->where('definition->trigger', $request->string('trigger')->toString()));
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'data' => WorkflowResource::collection($page->items()),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    public function show(Request $request, Workflow $workflow): WorkflowResource
    {
        $this->abortUnlessTenant($request, $workflow);

        return new WorkflowResource($workflow->load(['currentVersion.workflow', 'currentVersion.creator', 'runs.workflow']));
    }

    public function store(WorkflowUpsertRequest $request): WorkflowResource
    {
        $workflow = $this->versionService->create($request->user(), $request->validated());
        $this->auditLogger->log('workflow.created', $workflow, $request->user(), [], [
            'name' => $workflow->name,
            'status' => ApiStatus::workflow($workflow->status),
            'version' => $workflow->currentVersion?->version_number,
        ], $request);

        return new WorkflowResource($workflow);
    }

    public function update(WorkflowUpsertRequest $request, Workflow $workflow): WorkflowResource
    {
        $this->abortUnlessTenant($request, $workflow);
        $oldValues = $workflow->load('currentVersion')->only(['name', 'description', 'status', 'current_version_id']);
        $workflow = $this->versionService->update($workflow, $request->user(), $request->validated());
        $this->auditLogger->log('workflow.updated', $workflow, $request->user(), $oldValues, [
            'name' => $workflow->name,
            'status' => ApiStatus::workflow($workflow->status),
            'version' => $workflow->currentVersion?->version_number,
        ], $request);

        return new WorkflowResource($workflow);
    }

    public function archive(Request $request, Workflow $workflow): WorkflowResource
    {
        $this->abortUnlessTenant($request, $workflow);
        $oldValues = ['status' => ApiStatus::workflow($workflow->status)];
        $workflow->forceFill(['status' => 'archived', 'updated_by' => $request->user()->id])->save();
        $this->auditLogger->log('workflow.archived', $workflow, $request->user(), $oldValues, ['status' => 'archived'], $request);

        return new WorkflowResource($workflow->load(['currentVersion.creator', 'runs']));
    }

    public function validateDefinition(ValidateWorkflowDefinitionRequest $request): JsonResponse
    {
        $order = $this->definitionValidator->topologicalOrder($this->definitionValidator->validate($request->validated('definition')));

        return response()->json(['data' => ['valid' => true, 'topologicalOrder' => $order]]);
    }

    public function run(TriggerWorkflowRunRequest $request, Workflow $workflow): WorkflowRunResource
    {
        $this->abortUnlessTenant($request, $workflow);

        $run = $this->executionService->createRun($workflow, $request->user()->id, $request->validated('input') ?? []);
        $this->auditLogger->log('run.started', $run, $request->user(), [], [
            'workflowId' => $workflow->id,
            'trigger' => 'manual',
        ], $request);
        ExecuteWorkflowRunJob::dispatch($run->id);

        return new WorkflowRunResource($run->load(['workflow', 'stepRuns', 'executionLogs']));
    }

    private function abortUnlessTenant(Request $request, Workflow $workflow): void
    {
        abort_unless($workflow->tenant_id === $request->user()->tenant_id, 404);
    }

    private function paginationMeta(LengthAwarePaginator $page): array
    {
        return [
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'lastPage' => $page->lastPage(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
        ];
    }
}

