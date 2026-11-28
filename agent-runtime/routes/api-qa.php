<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Omnichannel\Addons\AgentRuntime\Http\AgentQaController;
use Omnichannel\Addons\AgentRuntime\Http\AgentQaInputController;
use Omnichannel\Addons\AgentRuntime\Http\AgentQaDatasetController;
use Omnichannel\Addons\AgentRuntime\Http\Middleware\AuthenticateAgentQaApi;

Route::prefix('api/agent-qa/v1')
    ->middleware([
        AuthenticateAgentQaApi::class,
        'throttle:agent-qa',
    ])
    ->group(static function (): void {
        Route::get('/health', [AgentQaController::class, 'health'])->name('agent-qa.health');
        Route::get('/projects', [AgentQaController::class, 'projects'])->name('agent-qa.projects');
        Route::post('/turns', [AgentQaController::class, 'turn'])->name('agent-qa.turns');
        Route::post('/input-only', AgentQaInputController::class)->name('agent-qa.input-only');
        Route::get('/datasets/{packageId}', AgentQaDatasetController::class)
            ->where('packageId', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('agent-qa.datasets.download');
        Route::get('/runs/{runUlid}/status', [AgentQaController::class, 'runStatus'])->name('agent-qa.runs.status');
        Route::get('/threads/{ulid}', [AgentQaController::class, 'showThread'])->name('agent-qa.threads.show');
    });
