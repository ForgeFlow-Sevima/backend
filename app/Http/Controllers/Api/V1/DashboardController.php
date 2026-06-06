<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkflowRunResource;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->summaryPayload($request->user()->tenant_id)]);
    }

    public function events(Request $request): StreamedResponse
    {
        $tenantId = $request->user()->tenant_id;
        $once = $request->boolean('once');

        return response()->stream(function () use ($tenantId, $once): void {
            do {
                $this->sendEvent('dashboard.updated', $this->realtimePayload($tenantId));

                if ($once) {
                    break;
                }

                sleep(2);
            } while (! connection_aborted());
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function realtimePayload(string $tenantId): array
    {
        return [
            'summary' => $this->summaryPayload($tenantId),
            'runningScheduledRuns' => WorkflowRunResource::collection(
                $this->dashboardRunQuery($tenantId)
                    ->where('trigger_type', 'scheduled')
                    ->where('status', 'running')
                    ->limit(5)
                    ->get(),
            ),
            'approvalRuns' => WorkflowRunResource::collection(
                $this->dashboardRunQuery($tenantId)
                    ->where('status', 'waiting_approval')
                    ->limit(5)
                    ->get(),
            ),
        ];
    }

    private function summaryPayload(string $tenantId): array
    {
        $since = now()->subDay();
        $runs = WorkflowRun::query()
            ->with('workflow')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $since)
            ->latest()
            ->get();
        $finishedRuns = $runs->whereIn('status', ['success', 'failed', 'timeout', 'cancelled'])->count();

        return [
            'totalWorkflows' => Workflow::query()->where('tenant_id', $tenantId)->count(),
            'activeWorkflows' => Workflow::query()->where('tenant_id', $tenantId)->where('status', 'active')->count(),
            'successRate' => $finishedRuns > 0 ? round(($runs->where('status', 'success')->count() / $finishedRuns) * 100, 1) : 0,
            'failedRuns' => $runs->whereIn('status', ['failed', 'timeout'])->count(),
            'recentRuns' => WorkflowRunResource::collection($runs->take(5)),
            'runVolume' => $this->runVolume($tenantId),
        ];
    }

    private function dashboardRunQuery(string $tenantId)
    {
        return WorkflowRun::query()
            ->with(['workflow', 'stepRuns', 'approvals.decidedBy'])
            ->where('tenant_id', $tenantId)
            ->latest();
    }

    private function sendEvent(string $event, mixed $payload): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode(['data' => $payload])."\n\n";
        @ob_flush();
        flush();
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
