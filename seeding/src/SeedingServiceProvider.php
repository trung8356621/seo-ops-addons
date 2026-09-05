<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding;

use App\Core\Capability\CapabilityRegistry;
use App\Core\Settings\SettingsSectionRegistry;
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
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingBootstrapController;
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingCommentGenerateController;
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingHealthController;
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingTopicController;
use Omnichannel\Addons\Seeding\LinkIntelligence\LinkExtractor;
use Omnichannel\Addons\Seeding\LinkIntelligence\LinkResourceService;
use Omnichannel\Addons\Seeding\LinkIntelligence\UrlNormalizer;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateService;
use Omnichannel\Addons\Seeding\Services\SeedingDatabaseConnectionService;
use Omnichannel\Addons\Seeding\Services\SeedingSocialPlatformDetector;
use Omnichannel\Addons\Seeding\Services\SeedingTopicService;
use Omnichannel\Addons\Seeding\Settings\SeedingSettingsSectionContributor;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingDatabaseHealth;
use Omnichannel\Addons\Seeding\Support\SeedingServiceHealth;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;
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
        $this->app->singleton(SeedingTopicService::class);
        $this->app->singleton(SeedingCommentGenerateService::class);
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
                Route::post('/comments/generate', SeedingCommentGenerateController::class)
                    ->name('seeding.comments.generate');
            });

        // Deprecated experimental CRUD — unused by canonical localStorage workspace.
        // Kept temporarily for old clients; prefer bootstrap/health only.
        Route::middleware($middleware)
            ->prefix('api/seeding/topics')
            ->group(function (): void {
                Route::get('/', [SeedingTopicController::class, 'index'])
                    ->name('seeding.topics.index');
                Route::post('/', [SeedingTopicController::class, 'store'])
                    ->name('seeding.topics.store');
                Route::get('/{topic}', [SeedingTopicController::class, 'show'])
                    ->whereNumber('topic')
                    ->name('seeding.topics.show');
                Route::patch('/{topic}', [SeedingTopicController::class, 'update'])
                    ->whereNumber('topic')
                    ->name('seeding.topics.update');
                Route::delete('/{topic}', [SeedingTopicController::class, 'destroy'])
                    ->whereNumber('topic')
                    ->name('seeding.topics.destroy');
            });

        // Thin legacy compatibility — old SEO-prefixed API aliases.
        Route::middleware($middleware)
            ->prefix('api/seo/seeding-topics')
            ->group(function (): void {
                Route::get('/', [SeedingTopicController::class, 'index'])
                    ->name('seo.seeding-topics.index');
                Route::post('/', [SeedingTopicController::class, 'store'])
                    ->name('seo.seeding-topics.store');
                Route::get('/{topic}', [SeedingTopicController::class, 'show'])
                    ->whereNumber('topic')
                    ->name('seo.seeding-topics.show');
                Route::patch('/{topic}', [SeedingTopicController::class, 'update'])
                    ->whereNumber('topic')
                    ->name('seo.seeding-topics.update');
                Route::delete('/{topic}', [SeedingTopicController::class, 'destroy'])
                    ->whereNumber('topic')
                    ->name('seo.seeding-topics.destroy');
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
