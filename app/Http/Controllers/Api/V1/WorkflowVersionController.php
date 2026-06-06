<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkflowResource;
use App\Http\Resources\WorkflowVersionResource;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Services\Workflow\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowVersionController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request, Workflow $workflow): JsonResponse
    {
        $this->abortUnlessTenant($request, $workflow);
        $versions = $workflow->versions()
            ->with(['workflow', 'creator'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderByDesc('version_number')
            ->get();

        return response()->json(['data' => WorkflowVersionResource::collection($versions)]);
    }

    public function rollback(Request $request, Workflow $workflow, WorkflowVersion $version): WorkflowResource
    {
        $this->abortUnlessTenant($request, $workflow);
        abort_unless($version->tenant_id === $request->user()->tenant_id && $version->workflow_id === $workflow->id, 404);

        $oldVersionId = $workflow->current_version_id;

        $workflow->forceFill([
            'current_version_id' => $version->id,
            'updated_by' => $request->user()->id,
        ])->save();

        $this->auditLogger->log('workflow.version.rollback', $workflow, $request->user(), [
            'currentVersionId' => $oldVersionId,
        ], [
            'currentVersionId' => $version->id,
            'version' => $version->version_number,
        ], $request);

        return new WorkflowResource($workflow->load(['currentVersion.workflow', 'currentVersion.creator', 'runs.workflow']));
    }

    private function abortUnlessTenant(Request $request, Workflow $workflow): void
    {
        abort_unless($workflow->tenant_id === $request->user()->tenant_id, 404);
    }
}
