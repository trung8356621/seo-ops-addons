<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Omnichannel\Addons\ContentProjects\Http\Controllers\ServiceApi\ContentProjectDraftIntakeController;
use Omnichannel\Addons\Seo\Http\Middleware\EnsureSeoServiceApi;

/*
| Content Projects Service API — Shared Planning Draft intake only.
| Included by Core routes/api-services.php under service.api + throttle:service-api.
| Auth: service_api_credentials. Scope: content-projects:draft:write.
| Does NOT expose generate/review/approve/schedule/publish/archive.
*/

Route::middleware([
    EnsureSeoServiceApi::class,
    'service.api.scope:content-projects:draft:write',
])->group(function (): void {
    Route::post('{service}/content-projects/draft/intake', [ContentProjectDraftIntakeController::class, 'store'])
        ->name('api.v1.services.content-projects.draft.intake');
});
