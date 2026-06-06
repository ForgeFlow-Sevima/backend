<?php

namespace App\Services\Workflow;

class WorkflowContextResolver
{
    public function resolvePath(string $path, array $context): mixed
    {
        $path = str_starts_with($path, '$.') ? substr($path, 2) : $path;
        $current = $context;

        foreach (explode('.', $path) as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
                continue;
            }

            if (is_array($current) && ctype_digit($segment) && array_key_exists((int) $segment, $current)) {
                $current = $current[(int) $segment];
                continue;
            }

            return null;
        }

        return $current;
    }

    public function resolveValue(mixed $value, array $context): mixed
    {
        if (is_array($value)) {
            return collect($value)->map(fn ($item) => $this->resolveValue($item, $context))->all();
        }

        if (! is_string($value)) {
            return $value;
        }

        $value = str_replace('${APP_URL}', rtrim((string) config('app.url'), '/'), $value);

        if (preg_match('/^\s*\{\{\s*([^}]+)\s*\}\}\s*$/', $value, $matches)) {
            return $this->resolvePath(trim($matches[1]), $context);
        }

        return preg_replace_callback('/\{\{\s*([^}]+)\s*\}\}/', function (array $matches) use ($context): string {
            $resolved = $this->resolvePath(trim($matches[1]), $context);

            return is_scalar($resolved) || $resolved === null ? (string) $resolved : json_encode($resolved, JSON_THROW_ON_ERROR);
        }, $value) ?? $value;
    }

    public function resolveConfig(array $config, array $context): array
    {
        return $this->resolveValue($config, $context);
    }
}
