<?php

namespace App\Http\Resources;

final class ApiStatus
{
    public static function workflow(?string $status): string
    {
        return $status === 'inactive' ? 'draft' : ($status ?? 'draft');
    }

    public static function workflowToDatabase(?string $status): ?string
    {
        return $status === 'draft' ? 'inactive' : $status;
    }

    public static function run(?string $status): string
    {
        return match ($status) {
            'pending' => 'queued',
            'retrying' => 'running',
            'skipped' => 'skipped',
            'waiting_approval' => 'waiting_approval',
            null => 'queued',
            default => $status,
        };
    }

    public static function runToDatabase(?string $status): ?string
    {
        return $status === 'queued' ? 'pending' : $status;
    }

    public static function log(?string $level): string
    {
        return $level === 'warning' ? 'warn' : ($level ?? 'info');
    }

    public static function logToDatabase(?string $level): ?string
    {
        return $level === 'warn' ? 'warning' : $level;
    }
}
