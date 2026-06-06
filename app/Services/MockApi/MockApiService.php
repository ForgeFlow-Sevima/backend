<?php

namespace App\Services\MockApi;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MockApiService
{
    public function order(string $orderId): array
    {
        $numericSeed = (int) preg_replace('/\D+/', '', $orderId) ?: 1001;
        $total = 125000 + (($numericSeed % 7) * 17500);

        return [
            'id' => $orderId,
            'status' => Cache::get($this->statusCacheKey($orderId), $numericSeed % 2 === 0 ? 'paid' : 'pending'),
            'total' => $total,
            'currency' => 'IDR',
            'customer' => [
                'id' => 'cus-'.$numericSeed,
                'name' => 'Mock Customer '.$numericSeed,
                'email' => 'customer'.$numericSeed.'@example.test',
            ],
            'items' => [
                ['sku' => 'SKU-ORDER-'.$numericSeed, 'name' => 'Workflow Test Item', 'qty' => 1, 'price' => $total],
            ],
            'createdAt' => now()->subMinutes($numericSeed % 60)->toIso8601String(),
        ];
    }

    public function notification(array $payload): array
    {
        return [
            'id' => (string) Str::uuid(),
            'status' => 'queued',
            'channel' => $payload['channel'],
            'recipient' => $payload['recipient'],
            'message' => $payload['message'],
            'metadata' => $payload['metadata'] ?? [],
            'queuedAt' => now()->toIso8601String(),
        ];
    }

    public function updateOrderStatus(string $orderId, array $payload): array
    {
        Cache::put($this->statusCacheKey($orderId), $payload['status'], now()->addHour());

        return [
            ...$this->order($orderId),
            'status' => $payload['status'],
            'statusNote' => $payload['note'] ?? null,
            'metadata' => $payload['metadata'] ?? [],
            'updatedAt' => now()->toIso8601String(),
        ];
    }

    public function handleHttpStep(string $method, string $url, mixed $body): ?array
    {
        $parts = parse_url($url);
        $appParts = parse_url((string) config('app.url'));
        $host = $parts['host'] ?? '';
        $appHost = $appParts['host'] ?? '';
        $path = trim($parts['path'] ?? '', '/');
        $method = strtoupper($method);

        if ($host !== $appHost || ! str_starts_with($path, 'api/mock/')) {
            return null;
        }

        if ($method === 'GET' && preg_match('#^api/mock/orders/([^/]+)$#', $path, $matches)) {
            return ['status' => 200, 'body' => ['data' => $this->order($matches[1])], 'headers' => ['X-FlowForge-Mock' => ['internal']]];
        }

        if ($method === 'POST' && $path === 'api/mock/notifications' && is_array($body)) {
            return ['status' => 201, 'body' => ['data' => $this->notification($body)], 'headers' => ['X-FlowForge-Mock' => ['internal']]];
        }

        if ($method === 'POST' && preg_match('#^api/mock/orders/([^/]+)/status$#', $path, $matches) && is_array($body)) {
            return ['status' => 200, 'body' => ['data' => $this->updateOrderStatus($matches[1], $body)], 'headers' => ['X-FlowForge-Mock' => ['internal']]];
        }

        return ['status' => 404, 'body' => ['message' => 'Mock endpoint not found.'], 'headers' => ['X-FlowForge-Mock' => ['internal']]];
    }

    private function statusCacheKey(string $orderId): string
    {
        return 'mock-api:orders:'.$orderId.':status';
    }
}
