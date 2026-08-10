<?php

declare(strict_types=1);

/**
 * Content Projects + Agent MCP API v1 (owner: addons/content-projects).
 * Loaded by SeoContentAi SeoPanelProvider until peer owns Route::group bootstrap.
 */

use Omnichannel\Addons\ContentProjects\Http\Controllers\Api\V1\ContentProjectApiController;
use Omnichannel\Addons\ContentProjects\Http\Controllers\Api\V1\ContentProjectAgentMcpController;
use Omnichannel\Addons\SearchFoundation\Http\Middleware\SetDynamicSeoDatabase;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', SetDynamicSeoDatabase::class])
    ->prefix('v1')
    ->group(function (): void {
        Route::get('/content-projects', [ContentProjectApiController::class, 'index']);
        Route::post('/content-projects', [ContentProjectApiController::class, 'store']);
        Route::get('/content-projects/{projectRef}', [ContentProjectApiController::class, 'show']);
        Route::patch('/content-projects/{projectRef}', [ContentProjectApiController::class, 'update']);

        Route::post('/content-projects/{projectRef}/items', [ContentProjectApiController::class, 'addItems']);
        Route::get('/content-projects/{projectRef}/items', [ContentProjectApiController::class, 'items']);
        Route::patch('/content-project-items/{itemRef}', [ContentProjectApiController::class, 'updateItem']);

        Route::post('/content-projects/{projectRef}/generate', [ContentProjectApiController::class, 'generate']);
        Route::post('/content-projects/{projectRef}/review', [ContentProjectApiController::class, 'review']);
        Route::post('/content-projects/{projectRef}/approve', [ContentProjectApiController::class, 'approve']);

        Route::post('/content-projects/{projectRef}/schedule', [ContentProjectApiController::class, 'schedule']);
        Route::post('/content-projects/{projectRef}/auto-schedule', [ContentProjectApiController::class, 'autoSchedule']);
        Route::post('/content-projects/{projectRef}/publish-now', [ContentProjectApiController::class, 'publishNow']);

        Route::post('/content-project-items/{itemRef}/retry-publish', [ContentProjectApiController::class, 'retryPublish']);
        Route::post('/content-project-items/{itemRef}/skip-publish', [ContentProjectApiController::class, 'skipPublish']);
        Route::post('/content-project-items/{itemRef}/cancel-publish', [ContentProjectApiController::class, 'cancelPublish']);

        Route::post('/content-projects/{projectRef}/archive', [ContentProjectApiController::class, 'archive']);
        Route::post('/content-projects/{projectRef}/restore', [ContentProjectApiController::class, 'restore']);

        Route::get('/content-projects/{projectRef}/runtime', [ContentProjectApiController::class, 'runtime']);
        Route::get('/content-projects/{projectRef}/timeline', [ContentProjectApiController::class, 'timeline']);
        Route::get('/content-projects/{projectRef}/publishing-queue', [ContentProjectApiController::class, 'publishingQueue']);

        Route::get('/agent/mcp/tools', [ContentProjectAgentMcpController::class, 'tools']);
        Route::post('/agent/mcp/tools', [ContentProjectAgentMcpController::class, 'tools']);
        Route::post('/agent/mcp/call', [ContentProjectAgentMcpController::class, 'call']);
        Route::post('/agent/execute', [ContentProjectAgentMcpController::class, 'execute']);
        Route::post('/agent/sessions', [ContentProjectAgentMcpController::class, 'storeSession']);
        Route::post('/agent/sessions/{sessionRef}/touch', [ContentProjectAgentMcpController::class, 'touchSession']);
    });
