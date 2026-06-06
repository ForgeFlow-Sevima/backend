<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreMockNotificationRequest;
use App\Http\Requests\Api\UpdateMockOrderStatusRequest;
use App\Services\MockApi\MockApiService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class MockApiController extends Controller
{
    public function __construct(private readonly MockApiService $mockApi) {}

    public function showOrder(string $orderId): JsonResponse
    {
        return response()->json(['data' => $this->mockApi->order($orderId)]);
    }

    public function storeNotification(StoreMockNotificationRequest $request): JsonResponse
    {
        $payload = $request->validated();

        return response()->json(['data' => $this->mockApi->notification($payload)], 201);
    }

    public function updateOrderStatus(UpdateMockOrderStatusRequest $request, string $orderId): JsonResponse
    {
        return response()->json(['data' => $this->mockApi->updateOrderStatus($orderId, $request->validated())]);
    }

    public function checkTime(): JsonResponse
    {
        return response()->json([
            'data' => [
                'time' => Carbon::now()->format('Y-m-d H:i:s'),
            ],
        ]);
    }
}
