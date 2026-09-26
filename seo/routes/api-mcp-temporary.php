<?php

declare(strict_types=1);

use App\Api\Middleware\ResolveTemporaryMcpAccess;
use Illuminate\Support\Facades\Route;
use Omnichannel\Addons\Seo\Http\Controllers\ServiceApi\TemporaryMcpAccessController;

/*
| Temporary site-bound MCP capability URLs.
| No permanent Bearer / AuthenticateServiceApi.
| Auth: ResolveTemporaryMcpAccess only.
*/

Route::middleware([
    ResolveTemporaryMcpAccess::class,
    'throttle:temporary-mcp',
])->group(function (): void {
    Route::get('{token}', [TemporaryMcpAccessController::class, 'index'])
        ->where('token', 'mcp_tmp_[A-Za-z0-9_-]+')
        ->name('api.v1.mcp.access.index');

    Route::get('{token}/{router}', [TemporaryMcpAccessController::class, 'show'])
        ->where('token', 'mcp_tmp_[A-Za-z0-9_-]+')
        ->where('router', '[A-Za-z0-9_\\-]+')
        ->name('api.v1.mcp.access.show');

    Route::post('{token}/{router}/read', [TemporaryMcpAccessController::class, 'read'])
        ->where('token', 'mcp_tmp_[A-Za-z0-9_-]+')
        ->where('router', '[A-Za-z0-9_\\-]+')
        ->name('api.v1.mcp.access.read');
});
