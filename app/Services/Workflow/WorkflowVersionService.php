<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;

class WorkflowVersionService
{
    public function __construct(private readonly WorkflowDefinitionValidator $validator) {}

    public function create(User $user, array $payload): Workflow
    {
        $definition = $this->validator->validate($payload['definition']);

        return DB::transaction(function () use ($user, $payload, $definition): Workflow {
            $workflow = Workflow::create([
                'tenant_id' => $user->tenant_id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'name' => $payload['name'],
                'description' => $payload['description'] ?? null,
                'status' => $payload['status'] === 'draft' ? 'inactive' : $payload['status'],
            ]);

            $version = $workflow->versions()->create([
                'tenant_id' => $user->tenant_id,
                'created_by' => $user->id,
                'version_number' => 1,
                'definition' => $definition,
                'change_note' => $payload['changeNote'] ?? 'Initial version',
            ]);

            $workflow->forceFill(['current_version_id' => $version->id])->save();

            return $workflow->load(['currentVersion.creator', 'runs']);
        });
    }

    public function update(Workflow $workflow, User $user, array $payload): Workflow
    {
        $definition = $this->validator->validate($payload['definition']);

        return DB::transaction(function () use ($workflow, $user, $payload, $definition): Workflow {
            $workflow->load('currentVersion');
            $nextVersion = ((int) $workflow->versions()->max('version_number')) + 1;

            $workflow->fill([
                'updated_by' => $user->id,
                'name' => $payload['name'],
                'description' => $payload['description'] ?? null,
                'status' => $payload['status'] === 'draft' ? 'inactive' : $payload['status'],
            ])->save();

            $version = $workflow->versions()->create([
                'tenant_id' => $workflow->tenant_id,
                'created_by' => $user->id,
                'version_number' => $nextVersion,
                'definition' => $definition,
                'change_note' => $payload['changeNote'] ?? "Version $nextVersion",
            ]);

            $workflow->forceFill(['current_version_id' => $version->id])->save();

            return $workflow->load(['currentVersion.creator', 'runs']);
        });
    }
}
