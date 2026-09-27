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
    });
