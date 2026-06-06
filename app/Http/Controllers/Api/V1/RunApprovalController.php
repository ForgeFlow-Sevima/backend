<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkflowApprovalResource;
use App\Http\Resources\WorkflowRunResource;
use App\Models\WorkflowApproval;
use App\Models\WorkflowRun;
use App\Services\Workflow\WorkflowExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RunApprovalController extends Controller
{
    public function __construct(private readonly WorkflowExecutionService $executionService) {}

    public function index(Request $request, WorkflowRun $run): JsonResponse
    {
        $this->abortUnlessTenant($request, $run);

        return response()->json([
            'data' => WorkflowApprovalResource::collection($run->approvals()->with('decidedBy')->latest()->get()),
        ]);
    }

    public function approve(Request $request, WorkflowRun $run, WorkflowApproval $approval): WorkflowRunResource
    {
        $this->abortUnlessTenant($request, $run);
        $this->abortUnlessApprovalBelongsToRun($approval, $run);

        $updatedRun = $this->executionService->resumeAfterApproval($approval, 'approved', $request->string('note')->toString() ?: null, $request->user()->id);

        return new WorkflowRunResource($updatedRun);
    }

    public function reject(Request $request, WorkflowRun $run, WorkflowApproval $approval): WorkflowRunResource
    {
        $this->abortUnlessTenant($request, $run);
        $this->abortUnlessApprovalBelongsToRun($approval, $run);

        $updatedRun = $this->executionService->resumeAfterApproval($approval, 'rejected', $request->string('note')->toString() ?: null, $request->user()->id);

        return new WorkflowRunResource($updatedRun);
    }

    private function abortUnlessTenant(Request $request, WorkflowRun $run): void
    {
        abort_unless($run->tenant_id === $request->user()->tenant_id, 404);
    }

    private function abortUnlessApprovalBelongsToRun(WorkflowApproval $approval, WorkflowRun $run): void
    {
        abort_unless($approval->tenant_id === $run->tenant_id && $approval->workflow_run_id === $run->id, 404);
    }
}
