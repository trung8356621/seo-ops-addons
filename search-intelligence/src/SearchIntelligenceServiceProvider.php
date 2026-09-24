<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence;

use App\Core\Capability\CapabilityRegistry;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Omnichannel\Addons\SearchIntelligence\Console\PreviewTopicSeedEvidenceCommand;
use Omnichannel\Addons\SearchIntelligence\Console\ReclusterSiteTopicsCommand;
use Omnichannel\Addons\SearchIntelligence\Contracts\TopicMembershipCapability;
use Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap\TopicalMapAuditController;
use Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap\TopicalMapAuditStatusController;
use Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap\TopicalMapChildrenController;
use Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap\TopicalMapNetworkController;
use Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap\TopicalMapOverviewController;
use Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap\TopicalMapTagsController;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipCapabilityService;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapAccess;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapVite;

/**
 * Peer addon: Search Intelligence + site-scoped Topic Core.
 */
final class SearchIntelligenceServiceProvider extends ServiceProvider
{
    public const SLUG = 'search-intelligence';

    public function register(): void
    {
        $this->app->singleton(TopicalMapVite::class);
        $this->app->singleton(TopicalMapAccess::class);
        $this->registerCapabilities();
    }

    public function boot(): void
    {
        $this->loadViewsFrom(
            dirname(__DIR__).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views',
            'search-intelligence',
        );

        $this->registerTopicalMapApiRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReclusterSiteTopicsCommand::class,
                PreviewTopicSeedEvidenceCommand::class,
            ]);
        }
    }

    private function registerTopicalMapApiRoutes(): void
    {
        $middleware = [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            \Illuminate\Auth\Middleware\Authenticate::class,
            SubstituteBindings::class,
        ];

        Route::middleware($middleware)
            ->prefix('seo/topical-map/api')
            ->group(function (): void {
                Route::get('/overview', TopicalMapOverviewController::class)
                    ->name('seo.topical-map.api.overview');
                Route::get('/topics/{topic}/children', TopicalMapChildrenController::class)
                    ->whereNumber('topic')
                    ->name('seo.topical-map.api.children');
                Route::get('/network', TopicalMapNetworkController::class)
                    ->name('seo.topical-map.api.network');
                Route::get('/tags', TopicalMapTagsController::class)
                    ->name('seo.topical-map.api.tags');
                Route::get('/audit-status', TopicalMapAuditStatusController::class)
                    ->name('seo.topical-map.api.audit-status');
                Route::post('/audit', TopicalMapAuditController::class)
                    ->name('seo.topical-map.api.audit');
            });
    }

    private function registerCapabilities(): void
    {
        if (! $this->app->bound(CapabilityRegistry::class)) {
            return;
        }

        /** @var CapabilityRegistry $caps */
        $caps = $this->app->make(CapabilityRegistry::class);
        foreach ($this->providedCapabilityIds() as $id) {
            if ($caps->has($id)) {
                continue;
            }
            if ($id === TopicMembershipCapability::ID) {
                $caps->register(
                    $id,
                    $this->app->make(TopicMembershipCapabilityService::class),
                    self::SLUG,
                );
                continue;
            }
            $caps->register($id, new CapabilityMarker($id, self::SLUG), self::SLUG);
        }
    }

    /** @return list<string> */
    private function providedCapabilityIds(): array
    {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'addon.json';
        if (! is_file($path)) {
            return [];
        }

        $meta = json_decode((string) file_get_contents($path), true);
        if (! is_array($meta) || ! is_array($meta['provides'] ?? null)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $meta['provides'])));
    }
}

final class CapabilityMarker
{
    public function __construct(
        public readonly string $id,
        public readonly string $ownerSlug,
    ) {}
}
