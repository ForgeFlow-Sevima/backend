<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiStatus;
use App\Http\Resources\ExecutionLogResource;
use App\Http\Resources\StepRunResource;
use App\Http\Resources\WorkflowApprovalResource;
use App\Http\Resources\WorkflowRunResource;
use App\Models\WorkflowRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RunController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = min(max((int) $request->integer('perPage', 15), 1), 100);
        $query = WorkflowRun::query()
            ->with(['workflow', 'stepRuns'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->latest();

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($query) use ($search): void {
                $query->where('id', $search)
                    ->orWhereHas('workflow', fn ($query) => $query->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('workflowId')) {
            $query->where('workflow_id', $request->string('workflowId')->toString());
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', ApiStatus::runToDatabase($request->string('status')->toString()));
        }

        if ($request->filled('trigger') && $request->trigger !== 'all') {
            $query->where('trigger_type', $request->string('trigger')->toString());
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to'));
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'data' => WorkflowRunResource::collection($page->items()),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    public function show(Request $request, WorkflowRun $run): WorkflowRunResource
    {
        $this->abortUnlessTenant($request, $run);

        return new WorkflowRunResource($run->load([
            'workflow',
            'stepRuns',
            'executionLogs' => fn ($query) => $query->latest()->limit(100),
            'executionLogs.workflowRun.workflow',
            'executionLogs.stepRun',
            'aiFailureAnalyses.stepRun',
            'approvals.decidedBy',
        ]));
    }

    public function logs(Request $request, WorkflowRun $run): JsonResponse
    {
        $this->abortUnlessTenant($request, $run);
        $query = $run->executionLogs()->with(['workflowRun.workflow', 'stepRun'])->latest();

        if ($request->filled('level') && $request->level !== 'all') {
            $query->where('level', ApiStatus::logToDatabase($request->string('level')->toString()));
        }

        if ($request->filled('stepRunId') && $request->stepRunId !== 'all') {
            $stepRunId = $request->string('stepRunId')->toString();
            $stepRunId === 'run' ? $query->whereNull('step_run_id') : $query->where('step_run_id', $stepRunId);
        }

        $limit = min((int) $request->integer('limit', 100), 500);
        $logs = $query->limit($limit)->get()->sortBy('created_at')->values();

        return response()->json(['data' => ExecutionLogResource::collection($logs)]);
    }

    public function events(Request $request, WorkflowRun $run): StreamedResponse
    {
        $this->abortUnlessTenant($request, $run);

        return response()->stream(function () use ($run): void {
            $sentLogs = [];
            $sentApprovals = [];
            $started = now();

            while (! connection_aborted()) {
                $freshRun = $run->fresh(['workflow', 'stepRuns', 'executionLogs.workflowRun.workflow', 'executionLogs.stepRun', 'aiFailureAnalyses.stepRun', 'approvals.decidedBy']);
                if (! $freshRun) {
                    break;
                }

                $this->sendEvent('run.updated', new WorkflowRunResource($freshRun));

                foreach ($freshRun->stepRuns as $stepRun) {
                    $this->sendEvent('step.updated', new StepRunResource($stepRun));
                }

                foreach ($freshRun->executionLogs->sortBy('created_at')->take(-50) as $log) {
                    if (isset($sentLogs[$log->id])) {
                        continue;
                    }
                    $sentLogs[$log->id] = true;
                    $this->sendEvent('log.created', new ExecutionLogResource($log));
                }

                foreach ($freshRun->approvals as $approval) {
                    if (isset($sentApprovals[$approval->id])) {
                        continue;
                    }
                    $sentApprovals[$approval->id] = true;
                    $this->sendEvent('approval.created', new WorkflowApprovalResource($approval));
                }

                echo ": heartbeat\n\n";
                @ob_flush();
                flush();

                if (in_array($freshRun->status, ['success', 'failed', 'timeout', 'cancelled'], true)) {
                    break;
                }

                if ($started->diffInSeconds(now()) >= 60) {
                    break;
                }

                sleep(1);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function sendEvent(string $event, mixed $payload): void
    {
        $data = $payload instanceof JsonResource ? $payload->resolve(request()) : $payload;

        echo 'event: '.$event."\n";
        echo 'data: '.json_encode(['data' => $data], JSON_THROW_ON_ERROR)."\n\n";
        @ob_flush();
        flush();
    }

    private function abortUnlessTenant(Request $request, WorkflowRun $run): void
    {
        abort_unless($run->tenant_id === $request->user()->tenant_id, 404);
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
