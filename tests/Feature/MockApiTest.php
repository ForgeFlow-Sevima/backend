<?php

use App\Services\Workflow\StepHandlers\HttpStepHandler;
use App\Support\MockApiUrls;
use Illuminate\Support\Facades\Cache;

it('serves a deterministic mock order', function () {
    $this->getJson('/api/mock/orders/1002')
        ->assertOk()
        ->assertJsonPath('data.id', '1002')
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.customer.email', 'customer1002@example.test');
});

it('queues a mock notification', function () {
    $this->postJson('/api/mock/notifications', [
        'channel' => 'email',
        'recipient' => 'ops@example.test',
        'message' => 'Order needs review',
        'metadata' => ['workflow' => 'test'],
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.channel', 'email');
});

it('updates mock order status for follow-up workflow steps', function () {
    Cache::forget('mock-api:orders:1003:status');

    $this->postJson('/api/mock/orders/1003/status', [
        'status' => 'processing',
        'note' => 'Accepted by workflow',
    ])
        ->assertOk()
        ->assertJsonPath('data.id', '1003')
        ->assertJsonPath('data.status', 'processing')
        ->assertJsonPath('data.statusNote', 'Accepted by workflow');

    $this->getJson('/api/mock/orders/1003')
        ->assertOk()
        ->assertJsonPath('data.status', 'processing');
});

it('resolves localhost mock endpoints inside workflow http steps without network roundtrip', function () {
    $output = app(HttpStepHandler::class)->handle([
        'type' => 'http',
        'config' => [
            'method' => 'GET',
            'url' => MockApiUrls::order('1'),
            'headers' => [],
            'body' => null,
            'timeoutMs' => 10000,
        ],
    ], ['input' => []]);

    expect($output['status'])->toBe(200)
        ->and($output['body']['data']['id'])->toBe('1')
        ->and($output['headers']['X-FlowForge-Mock'][0])->toBe('internal');
});

it('fails workflow http steps when localhost mock endpoint returns an error', function () {
    app(HttpStepHandler::class)->handle([
        'type' => 'http',
        'config' => [
            'method' => 'GET',
            'url' => MockApiUrls::order(''),
            'headers' => [],
            'body' => null,
            'timeoutMs' => 10000,
        ],
    ], ['input' => []]);
})->throws(RuntimeException::class, 'Mock endpoint not found.');

it('resolves app url placeholders inside workflow http step configs', function () {
    config()->set('app.url', 'http://backend.test');

    $output = app(HttpStepHandler::class)->handle([
        'type' => 'http',
        'config' => [
            'method' => 'GET',
            'url' => '${APP_URL}/api/mock/orders/1',
            'headers' => [],
            'body' => null,
            'timeoutMs' => 10000,
        ],
    ], ['input' => []]);

    expect($output['status'])->toBe(200)
        ->and($output['body']['data']['id'])->toBe('1');
});
