<?php

namespace App\Services\Workflow;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    public function log(string $action, ?Model $entity, ?User $user = null, array $oldValues = [], array $newValues = [], ?Request $request = null): AuditLog
    {
        return AuditLog::create([
            'tenant_id' => $user?->tenant_id ?? $entity?->tenant_id,
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entity ? $entity::class : null,
            'entity_id' => $entity?->getKey(),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
