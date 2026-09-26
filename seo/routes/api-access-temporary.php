<?php

declare(strict_types=1);

use App\Api\Middleware\ResolveTemporaryServiceAccess;
use Illuminate\Support\Facades\Route;
use Omnichannel\Addons\Seo\Http\Controllers\ServiceApi\TemporarySeoAccessController;

/*
| Temporary site-bound SEO Access URLs (read-only).
| No permanent Bearer / AuthenticateServiceApi.
| Auth: ResolveTemporaryServiceAccess only.
*/

Route::middleware([
    ResolveTemporaryServiceAccess::class,
    'throttle:temporary-access',
])->group(function (): void {
    Route::get('{token}', [TemporarySeoAccessController::class, 'index'])
        ->where('token', 'access_tmp_[A-Za-z0-9_-]+')
        ->name('api.v1.access.index');

    Route::get('{token}/site', [TemporarySeoAccessController::class, 'site'])
        ->where('token', 'access_tmp_[A-Za-z0-9_-]+')
        ->name('api.v1.access.site');

    Route::get('{token}/content', [TemporarySeoAccessController::class, 'content'])
        ->where('token', 'access_tmp_[A-Za-z0-9_-]+')
        ->name('api.v1.access.content');

    Route::get('{token}/keywords', [TemporarySeoAccessController::class, 'keywords'])
        ->where('token', 'access_tmp_[A-Za-z0-9_-]+')
        ->name('api.v1.access.keywords');

    Route::post('{token}/keywords', [TemporarySeoAccessController::class, 'keywordsQuery'])
        ->where('token', 'access_tmp_[A-Za-z0-9_-]+')
        ->name('api.v1.access.keywords.query');

    Route::get('{token}/gsc', [TemporarySeoAccessController::class, 'gsc'])
        ->where('token', 'access_tmp_[A-Za-z0-9_-]+')
        ->name('api.v1.access.gsc');

    Route::post('{token}/gsc', [TemporarySeoAccessController::class, 'gscQuery'])
        ->where('token', 'access_tmp_[A-Za-z0-9_-]+')
        ->name('api.v1.access.gsc.query');
});
