<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Omnichannel\Addons\Seo\Http\Controllers\ServiceApi\SeoAccessController;
use Omnichannel\Addons\Seo\Http\Middleware\EnsureSeoServiceApi;

/*
| SEO Service API — Access index + temporary site-bound access mint.
| Included by Core routes/api-services.php under service.api + throttle:service-api.
| Auth plane: service_api_credentials only. Scope: seo:read.
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
