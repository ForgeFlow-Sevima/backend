<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkflowRunResource;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $since = now()->subDay();
        $runs = WorkflowRun::query()
            ->with('workflow')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $since)
            ->latest()
            ->get();
        $finishedRuns = $runs->whereIn('status', ['success', 'failed', 'timeout', 'cancelled'])->count();

        return response()->json([
            'data' => [
                'totalWorkflows' => Workflow::query()->where('tenant_id', $tenantId)->count(),
                'activeWorkflows' => Workflow::query()->where('tenant_id', $tenantId)->where('status', 'active')->count(),
                'successRate' => $finishedRuns > 0 ? round(($runs->where('status', 'success')->count() / $finishedRuns) * 100, 1) : 0,
                'failedRuns' => $runs->whereIn('status', ['failed', 'timeout'])->count(),
                'recentRuns' => WorkflowRunResource::collection($runs->take(5)),
                'runVolume' => $this->runVolume($tenantId),
            ],
        ]);
    }

    private function runVolume(string $tenantId): array
    {
        $buckets = collect(range(5, 0))->map(function (int $offset) use ($tenantId): array {
            $start = now()->subHours($offset * 4)->startOfHour();
            $end = (clone $start)->addHours(4);
            $runs = WorkflowRun::query()
                ->where('tenant_id', $tenantId)
                ->whereBetween('created_at', [$start, $end])
                ->get(['status']);

            return [
                'time' => $start->format('H:00'),
                'runs' => $runs->count(),
                'failures' => $runs->whereIn('status', ['failed', 'timeout'])->count(),
            ];
        });

        return $buckets->values()->all();
    }
}
