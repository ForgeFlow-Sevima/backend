<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $definition = $this->currentVersion?->definition ?? [];
        $steps = collect($definition['steps'] ?? [])->values();
        $runs = $this->relationLoaded('runs') ? $this->runs : collect();
        $successRuns = $runs->where('status', 'success')->count();
        $finishedRuns = $runs->whereIn('status', ['success', 'failed', 'timeout', 'cancelled'])->count();
        $avgDuration = (int) round($runs->whereNotNull('duration_ms')->avg('duration_ms') ?? 0);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description ?? '',
            'status' => ApiStatus::workflow($this->status),
            'trigger' => $definition['trigger'] ?? 'manual',
            'version' => $this->currentVersion?->version_number ?? 1,
            'lastRunAt' => $this->last_run_at?->toIso8601String(),
            'successRate' => $finishedRuns > 0 ? round(($successRuns / $finishedRuns) * 100, 1) : 0,
            'avgDurationMs' => $avgDuration,
            'steps' => $steps->map(fn ($step) => [
                'id' => $step['id'] ?? '',
                'label' => $step['label'] ?? $step['name'] ?? $step['id'] ?? 'Untitled step',
                'type' => $step['type'] ?? 'http',
                'dependsOn' => $step['dependsOn'] ?? $step['depends_on'] ?? [],
                'status' => isset($step['status']) ? ApiStatus::run($step['status']) : null,
            ])->all(),
            'activeVersion' => new WorkflowVersionResource($this->whenLoaded('currentVersion')),
            'definition' => $definition,
            'dag' => [
                'nodes' => $steps->map(fn ($step) => [
                    'id' => $step['id'] ?? '',
                    'label' => $step['label'] ?? $step['name'] ?? $step['id'] ?? 'Untitled step',
                    'type' => $step['type'] ?? 'http',
                ])->all(),
                'edges' => $steps->flatMap(function ($step) {
                    $target = $step['id'] ?? '';

                    return collect($step['dependsOn'] ?? $step['depends_on'] ?? [])->map(fn ($source) => [
                        'id' => $source.'-'.$target,
                        'source' => $source,
                        'target' => $target,
                    ]);
                })->values()->all(),
            ],
        ];
    }
}
