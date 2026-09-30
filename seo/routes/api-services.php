<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Omnichannel\Addons\Seo\Http\Controllers\ServiceApi\SeoAccessController;
use Omnichannel\Addons\Seo\Http\Controllers\ServiceApi\SeoToolApiController;
use Omnichannel\Addons\Seo\Http\Middleware\EnsureSeoServiceApi;

/*
| SEO Service API — Access index + temporary site-bound access mint.
| Included by Core routes/api-services.php under service.api + throttle:service-api.
| Auth plane: service_api_credentials only.
*/

Route::middleware([
    EnsureSeoServiceApi::class,
    'service.api.scope:seo:read',
])->group(function (): void {
    Route::get('{service}/access', [SeoAccessController::class, 'index'])
        ->name('api.v1.services.access.index');

    Route::post('{service}/access', [SeoAccessController::class, 'mint'])
        ->name('api.v1.services.access.mint');
});

/*
| SEO Tool API — Capability discovery & execution boundary.
| Authentication enforced by EnsureSeoServiceApi; granular scopes and confirmation
| policies are enforced per-tool by SeoToolExecutor.
*/
Route::middleware([
    EnsureSeoServiceApi::class,
])->group(function (): void {
    Route::get('{service}/tools', [SeoToolApiController::class, 'index'])
        ->name('api.v1.services.seo.tools.index');

    Route::post('{service}/tools/{toolKey}/execute', [SeoToolApiController::class, 'execute'])
        ->name('api.v1.services.seo.tools.execute');
});
