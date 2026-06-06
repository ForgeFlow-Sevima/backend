<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiStatus;
use App\Http\Resources\ExecutionLogResource;
use App\Models\ExecutionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class LogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $perPage = min(max((int) $request->integer('perPage', 100), 1), 500);
        $query = ExecutionLog::query()
            ->with('workflowRun.workflow')
            ->where('tenant_id', $request->user()->tenant_id)
            ->latest();

        if ($request->filled('level') && $request->level !== 'all') {
            $query->where('level', ApiStatus::logToDatabase($request->string('level')->toString()));
        }

        if ($request->filled('workflowId') && $request->workflowId !== 'all') {
            $workflowId = $request->string('workflowId')->toString();
            $query->whereHas('workflowRun', fn ($query) => $query->where('workflow_id', $workflowId));
        }

        if ($request->filled('query')) {
            $search = $request->string('query')->toString();
            $query->where(function ($query) use ($search): void {
                $query->where('message', 'like', "%{$search}%")
                    ->orWhereHas('workflowRun.workflow', fn ($query) => $query->where('name', 'like', "%{$search}%"));
            });
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'data' => ExecutionLogResource::collection($page->items()),
            'meta' => $this->paginationMeta($page),
        ]);
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
