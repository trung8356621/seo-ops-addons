<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Omnichannel\Addons\Seo\Http\Controllers\ServiceApi\SeoMcpController;
use Omnichannel\Addons\Seo\Http\Middleware\EnsureSeoServiceApi;

/*
| SEO Service API — MCP discovery + selective read + temporary access mint.
| Included by Core routes/api-services.php under service.api + throttle:service-api.
| Auth plane: service_api_credentials only. Scope: mcp:read.
*/

Route::middleware([
    EnsureSeoServiceApi::class,
    'service.api.scope:mcp:read',
])->group(function (): void {
    Route::get('{service}/mcp', [SeoMcpController::class, 'index'])
        ->name('api.v1.services.mcp.index');

    Route::post('{service}/mcp/access', [SeoMcpController::class, 'mintAccess'])
        ->name('api.v1.services.mcp.access.mint');

    Route::get('{service}/mcp/{router}', [SeoMcpController::class, 'show'])
        ->where('router', '[A-Za-z0-9_\\-]+')
        ->name('api.v1.services.mcp.show');

    Route::post('{service}/mcp/{router}/read', [SeoMcpController::class, 'read'])
        ->where('router', '[A-Za-z0-9_\\-]+')
        ->name('api.v1.services.mcp.read');
});
