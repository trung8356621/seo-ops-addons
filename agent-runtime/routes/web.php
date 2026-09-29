<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Omnichannel\Addons\AgentRuntime\Http\AgentRuntimeController;

Route::middleware(['web', 'auth'])
    ->prefix('agent-runtime')
    ->group(static function (): void {
        Route::get('/projects', [AgentRuntimeController::class, 'projects'])->name('agent-runtime.projects');
        Route::post('/turns', [AgentRuntimeController::class, 'turn'])->name('agent-runtime.turns');
        Route::post('/model-input', [AgentRuntimeController::class, 'modelInput'])->name('agent-runtime.model-input');
        Route::post('/model-debug/start', [AgentRuntimeController::class, 'modelDebugStart'])->name('agent-runtime.model-debug.start');
        Route::post('/model-debug/apply', [AgentRuntimeController::class, 'modelDebugApply'])->name('agent-runtime.model-debug.apply');

        // Thread management
        Route::post('/threads', [AgentRuntimeController::class, 'createThread'])->name('agent-runtime.threads.create');
        Route::get('/threads', [AgentRuntimeController::class, 'listThreads'])->name('agent-runtime.threads.list');
        Route::get('/threads/{ulid}', [AgentRuntimeController::class, 'showThread'])->name('agent-runtime.threads.show');
        Route::post('/threads/{ulid}/turns', [AgentRuntimeController::class, 'threadTurn'])->name('agent-runtime.threads.turn');
    });
