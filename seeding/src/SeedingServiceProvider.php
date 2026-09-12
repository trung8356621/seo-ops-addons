<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding;

use App\Core\Capability\CapabilityRegistry;
use App\Core\Event\ArticleIndexStatusChanged;
use App\Core\Event\EventBus;
use App\Core\Settings\SettingsSectionRegistry;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Omnichannel\Addons\Seeding\Console\SeedingDbCheckCommand;
use Omnichannel\Addons\Seeding\Filament\Pages\SeedingTopicsPage;
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingBootstrapController;
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingCommentGenerateController;
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingFeedController;
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingHealthController;
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingLinkPreviewController;
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingManagerTopicsController;
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingReportController;
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingShareTopicController;
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingTopicController;
use Omnichannel\Addons\Seeding\Http\Controllers\WebsiteShareFeedController;
use Omnichannel\Addons\Seeding\LinkIntelligence\LinkExtractor;
use Omnichannel\Addons\Seeding\LinkIntelligence\LinkResourceService;
use Omnichannel\Addons\Seeding\LinkIntelligence\UrlNormalizer;
use Omnichannel\Addons\Seeding\Listeners\ArticleIndexStatusChangedListener;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateService;
use Omnichannel\Addons\Seeding\Services\SeedingDatabaseConnectionService;
use Omnichannel\Addons\Seeding\Services\SeedingLinkPreviewService;
use Omnichannel\Addons\Seeding\Services\SeedingReportService;
use Omnichannel\Addons\Seeding\Services\SeedingSharedTopicService;
use Omnichannel\Addons\Seeding\Services\SeedingSocialPlatformDetector;
use Omnichannel\Addons\Seeding\Services\WebsiteShareJobService;
use Omnichannel\Addons\Seeding\Settings\SeedingSettingsSectionContributor;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingDatabaseHealth;
use Omnichannel\Addons\Seeding\Support\SeedingOutboundUrlPolicy;
use Omnichannel\Addons\Seeding\Support\SeedingServiceHealth;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;
use Omnichannel\Addons\Seeding\Support\SeedingTargetCalculator;
use Omnichannel\Addons\Seeding\Support\SeedingTopicAuthorization;
use Omnichannel\Addons\Seeding\Support\SeedingVite;
use Throwable;

final class SeedingServiceProvider extends ServiceProvider
{
    public const SLUG = 'seeding';

    public function register(): void
    {
        $this->app->singleton(UrlNormalizer::class);
        $this->app->singleton(LinkExtractor::class);
        $this->app->singleton(LinkResourceService::class);
        $this->app->singleton(SeedingSocialPlatformDetector::class);
        $this->app->singleton(SeedingTargetCalculator::class);
        $this->app->singleton(SeedingSharedTopicService::class);
        $this->app->singleton(SeedingReportService::class);
        $this->app->singleton(WebsiteShareJobService::class);
        $this->app->singleton(SeedingCommentGenerateService::class);
        $this->app->singleton(SeedingOutboundUrlPolicy::class);
        $this->app->singleton(SeedingLinkPreviewService::class);
        $this->app->singleton(SeedingTopicAuthorization::class);
        $this->app->singleton(SeedingAccess::class);
        $this->app->singleton(SeedingServiceResolver::class);
        $this->app->singleton(SeedingDatabaseConnectionService::class);
        $this->app->singleton(SeedingDatabaseHealth::class);
        $this->app->singleton(SeedingServiceHealth::class);
        $this->app->singleton(SeedingSettingsSectionContributor::class);
        $this->app->singleton(SeedingVite::class);

        $this->registerCapabilities();
    }

    public function boot(): void
    {
        $root = dirname(__DIR__);
        // Active migration plane is owned via config/addon_migration_ownership.php → omi_seeding.
        // Do not loadMigrationsFrom() here — avoids accidental default-connection runs.
        $this->loadViewsFrom($root.'/resources/views', 'seeding');
        $this->loadTranslationsFrom($root.'/resources/lang', 'seeding');

        app(SeedingServiceResolver::class)->ensureCatalogRow();

        try {
            app(SeedingDatabaseConnectionService::class)->bootstrap();
        } catch (Throwable) {
            // ENV / missing table — health endpoints report status; do not crash boot.
        }

        if ($this->app->bound(SettingsSectionRegistry::class)) {
            $settings = $this->app->make(SettingsSectionRegistry::class);
            if (! $settings->hasContributor(self::SLUG)) {
                $settings->register($this->app->make(SeedingSettingsSectionContributor::class));
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SeedingDbCheckCommand::class]);
        }

        $this->registerRoutes();
        $this->registerLegacyUiRedirects();
        $this->registerSeoPanelTopLevelNav();
        $this->registerIndexStatusListener();
    }

    private function registerIndexStatusListener(): void
    {
        if (! $this->app->bound(EventBus::class)) {
            return;
        }

        /** @var EventBus $bus */
        $bus = $this->app->make(EventBus::class);
        $bus->listen(
            ArticleIndexStatusChanged::NAME,
            $this->app->make(ArticleIndexStatusChangedListener::class)
        );
    }

    /**
     * Peer shortcut on SEO sidebar — top-level, not nested under SEO module.
     * Canonical surface remains /seeding (own Filament panel).
     */
    private function registerSeoPanelTopLevelNav(): void
    {
        Filament::serving(function (): void {
            $panelId = Filament::getCurrentPanel()?->getId();
            if (! in_array($panelId, ['seo', 'seo-main'], true)) {
                return;
            }

            if (! SeedingTopicsPage::canAccess()) {
                return;
            }

            $sort = 55;
            if (class_exists(\Omnichannel\Addons\Seo\Support\SeoUserNavigation::class)) {
                $sort = (int) constant(\Omnichannel\Addons\Seo\Support\SeoUserNavigation::class.'::SORT_SEEDING');
            }

            Filament::registerNavigationItems([
                NavigationItem::make(SeedingTopicsPage::getNavigationLabel())
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->url(url('/seeding'))
                    ->sort($sort)
                    ->isActiveWhen(static fn (): bool => request()->is('seeding') || request()->is('seeding/*')),
            ]);
        });
    }

    private function registerRoutes(): void
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
            ->prefix('api/seeding')
            ->group(function (): void {
                Route::get('/bootstrap', SeedingBootstrapController::class)
                    ->name('seeding.bootstrap');
                Route::get('/health', SeedingHealthController::class)
                    ->name('seeding.health');
                Route::get('/feed', SeedingFeedController::class)
                    ->name('seeding.feed');
                Route::post('/topics/share', SeedingShareTopicController::class)
                    ->name('seeding.topics.share');
                Route::post('/reports', SeedingReportController::class)
                    ->name('seeding.reports.store');
                Route::post('/comments/generate', SeedingCommentGenerateController::class)
                    ->name('seeding.comments.generate');
                Route::post('/link-preview', SeedingLinkPreviewController::class)
                    ->name('seeding.link-preview');

                Route::get('/manager/topics', [SeedingManagerTopicsController::class, 'index'])
                    ->name('seeding.manager.topics');
                Route::post('/manager/topics/{topicId}/pause', [SeedingManagerTopicsController::class, 'pause'])
                    ->whereNumber('topicId')
                    ->name('seeding.manager.topics.pause');
                Route::post('/manager/topics/{topicId}/resume', [SeedingManagerTopicsController::class, 'resume'])
                    ->whereNumber('topicId')
                    ->name('seeding.manager.topics.resume');
                Route::post('/manager/topics/{topicId}/cancel', [SeedingManagerTopicsController::class, 'cancel'])
                    ->whereNumber('topicId')
                    ->name('seeding.manager.topics.cancel');

                Route::get('/website-share', [WebsiteShareFeedController::class, 'index'])
                    ->name('seeding.website-share.index');
                Route::post('/website-share/{jobId}/content', [WebsiteShareFeedController::class, 'updateContent'])
                    ->whereNumber('jobId')
                    ->name('seeding.website-share.content');
                Route::post('/website-share/{jobId}/report', [WebsiteShareFeedController::class, 'report'])
                    ->whereNumber('jobId')
                    ->name('seeding.website-share.report');
            });

        // Legacy site-scoped CRUD retired — JSON 410 only (no Livewire/Filament toast).
        Route::middleware($middleware)
            ->prefix('api/seeding/topics')
            ->group(function (): void {
                Route::any('/{any?}', [SeedingTopicController::class, 'gone'])
                    ->where('any', '.*')
                    ->name('seeding.topics.legacy');
            });

        Route::middleware($middleware)
            ->prefix('api/seo/seeding-topics')
            ->group(function (): void {
                Route::any('/{any?}', [SeedingTopicController::class, 'gone'])
                    ->where('any', '.*')
                    ->name('seo.seeding-topics.legacy');
            });
    }

    /**
     * Old SEO-hosted Seeding UI paths → canonical /seeding (query string preserved).
     */
    private function registerLegacyUiRedirects(): void
    {
        $redirect = static function () {
            $qs = request()->getQueryString();

            return redirect('/seeding'.($qs !== null && $qs !== '' ? '?'.$qs : ''));
        };

        Route::middleware(['web', 'auth'])
            ->group(function () use ($redirect): void {
                Route::get('/seo/seeding-topics', $redirect)
                    ->name('seeding.legacy.seo-main.topics');
                Route::get('/seo/seeding-topic-manage', $redirect)
                    ->name('seeding.legacy.seo-main.manage');
                Route::get('/seo/{connection_hash}/seeding-topics', $redirect)
                    ->where(['connection_hash' => '[a-zA-Z0-9]{32,64}'])
                    ->name('seeding.legacy.seo-hash.topics');
                Route::get('/seo/{connection_hash}/seeding-topic-manage', $redirect)
                    ->where(['connection_hash' => '[a-zA-Z0-9]{32,64}'])
                    ->name('seeding.legacy.seo-hash.manage');
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
            $caps->register($id, new CapabilityMarker($id, self::SLUG), self::SLUG);
        }
    }

    /** @return list<string> */
    private function providedCapabilityIds(): array
    {
        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'addon.json';
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
