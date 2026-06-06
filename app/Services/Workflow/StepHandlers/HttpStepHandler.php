<?php

namespace App\Services\Workflow\StepHandlers;

use App\Services\MockApi\MockApiService;
use App\Services\Workflow\WorkflowContextResolver;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpStepHandler implements StepHandler
{
    public function __construct(private readonly WorkflowContextResolver $resolver = new WorkflowContextResolver) {}

    public function handle(array $step, array $context): array
    {
        $config = $this->resolver->resolveConfig($step['config'] ?? [], $context);
        $method = strtolower((string) ($config['method'] ?? 'GET'));
        $timeoutSeconds = max(1, (int) (($config['timeoutMs'] ?? 10000) / 1000));
        $headers = is_array($config['headers'] ?? null) ? $config['headers'] : [];
        $body = $config['body'] ?? null;

        $mockResponse = app(MockApiService::class)->handleHttpStep($method, (string) $config['url'], $body);
        if ($mockResponse !== null) {
            if (($mockResponse['status'] ?? 500) >= 400) {
                $message = is_array($mockResponse['body'] ?? null) ? ($mockResponse['body']['message'] ?? 'Mock HTTP request failed.') : 'Mock HTTP request failed.';

                throw new RuntimeException($message);
            }

            return [
                ...$mockResponse,
                'input' => $context['input'] ?? [],
            ];
        }

        $response = Http::timeout($timeoutSeconds)
            ->connectTimeout(5)
            ->withHeaders($headers)
            ->send($method, (string) $config['url'], $body === null ? [] : ['json' => $body])
            ->throw();

        return [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
            'headers' => $response->headers(),
            'input' => $context['input'] ?? [],
        ];
    }
}
