<?php

use App\Http\Controllers\Api\MockApiController;
use App\Http\Controllers\Api\V1\AiFailureAnalysisController;
use App\Http\Controllers\Api\V1\AiWorkflowDraftController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\LogController;
use App\Http\Controllers\Api\V1\RunApprovalController;
use App\Http\Controllers\Api\V1\RunController;
use App\Http\Controllers\Api\V1\ScheduledTriggerController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WorkflowController;
use App\Http\Controllers\Api\V1\WorkflowVersionController;
use App\Http\Controllers\Api\WebhookIncomingController;
use Illuminate\Support\Facades\Route;

Route::prefix('mock')->group(function (): void {
    Route::get('orders/{orderId}', [MockApiController::class, 'showOrder']);
    Route::post('notifications', [MockApiController::class, 'storeNotification']);
    Route::post('orders/{orderId}/status', [MockApiController::class, 'updateOrderStatus']);
    Route::get('time', [MockApiController::class, 'checkTime']);
});

Route::post('webhooks/workflows/{workflow}', [WebhookIncomingController::class, 'store']);

Route::prefix('v1')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');

    Route::middleware('api.token')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('dashboard/events', [DashboardController::class, 'events']);

        Route::get('workflows', [WorkflowController::class, 'index'])->middleware('throttle:60,1');
        Route::post('workflows', [WorkflowController::class, 'store'])->middleware('role:admin,editor');
        Route::post('workflows/validate', [WorkflowController::class, 'validateDefinition'])->middleware('role:admin,editor');
        Route::get('workflows/{workflow}/scheduled-triggers', [ScheduledTriggerController::class, 'indexForWorkflow']);
        Route::get('workflows/{workflow}', [WorkflowController::class, 'show']);
        Route::patch('workflows/{workflow}', [WorkflowController::class, 'update'])->middleware('role:admin,editor');
        Route::patch('workflows/{workflow}/archive', [WorkflowController::class, 'archive'])->middleware('role:admin,editor');
        Route::post('workflows/{workflow}/runs', [WorkflowController::class, 'run'])->middleware('role:admin,editor');
        Route::get('workflows/{workflow}/versions', [WorkflowVersionController::class, 'index']);
        Route::post('workflows/{workflow}/versions/{version}/rollback', [WorkflowVersionController::class, 'rollback'])->middleware('role:admin,editor');

        Route::get('runs', [RunController::class, 'index'])->middleware('throttle:60,1');
        Route::get('logs', [LogController::class, 'index'])->middleware('throttle:60,1');
        Route::get('runs/{run}', [RunController::class, 'show']);
        Route::get('runs/{run}/logs', [RunController::class, 'logs']);
        Route::get('runs/{run}/events', [RunController::class, 'events']);
        Route::get('runs/{run}/approvals', [RunApprovalController::class, 'index']);
        Route::post('runs/{run}/approvals/{approval}/approve', [RunApprovalController::class, 'approve'])->middleware('role:admin,editor');
        Route::post('runs/{run}/approvals/{approval}/reject', [RunApprovalController::class, 'reject'])->middleware('role:admin,editor');
        Route::get('runs/{run}/ai-analysis', [AiFailureAnalysisController::class, 'show']);
        Route::post('runs/{run}/ai-analysis', [AiFailureAnalysisController::class, 'store'])->middleware('role:admin,editor');

        Route::post('ai/workflow-drafts', [AiWorkflowDraftController::class, 'store'])->middleware('role:admin,editor');

        Route::get('scheduled-triggers', [ScheduledTriggerController::class, 'index']);
        Route::post('scheduled-triggers', [ScheduledTriggerController::class, 'store'])->middleware('role:admin,editor');
        Route::patch('scheduled-triggers/{trigger}', [ScheduledTriggerController::class, 'update'])->middleware('role:admin,editor');
        Route::post('scheduled-triggers/{trigger}/run-now', [ScheduledTriggerController::class, 'runNow'])->middleware('role:admin,editor');

        Route::get('users', [UserController::class, 'index'])->middleware(['role:admin', 'throttle:60,1']);
        Route::post('users', [UserController::class, 'store'])->middleware('role:admin');
        Route::patch('users/{user}/role', [UserController::class, 'updateRole'])->middleware('role:admin');
    });
});
