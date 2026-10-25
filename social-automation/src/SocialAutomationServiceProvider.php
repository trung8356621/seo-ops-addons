<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation;

use App\Core\Capability\CapabilityRegistry;
use App\Core\Workspace\WorkspaceDestination;
use App\Core\Workspace\WorkspaceDestinationRegistry;
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
use Omnichannel\Addons\SocialAutomation\Console\SocialAutomationScheduleTickCommand;
use Omnichannel\Addons\SocialAutomation\Domain\EligibilitySelector;
use Omnichannel\Addons\SocialAutomation\Domain\JobStateMachine;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Auth\RpaAdminRoleGate;
use Omnichannel\Addons\SocialAutomation\Auth\RpaAdminSessionService;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaAdmin\RpaAdminAuthController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaAdmin\RpaAdminScriptSyncController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ScriptReleaseMediaController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\SocialAutomationBootstrapController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\SocialAutomationHealthController;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaAdminUserApi;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\RenderRpaAdminRateLimitJson;
use Omnichannel\Addons\SocialAutomation\Orchestration\JobOrchestrator;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackagePublishValidator;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageRegistryService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptCatalogService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptRegistryUiService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptReleaseMediaService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptRemoveService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptSyncPlanner;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptTombstoneService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\WidgetInvalidationService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\WidgetWorkflowComposerService;
use Omnichannel\Addons\SocialAutomation\Registry\AgentRegistryService;
use Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService;
use Omnichannel\Addons\SocialAutomation\Scheduling\ScheduleDispatcher;
use Omnichannel\Addons\SocialAutomation\Services\SocialAutomationDatabaseConnectionService;
use Omnichannel\Addons\SocialAutomation\Support\DashboardMetrics;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationDatabaseHealth;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceHealth;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceResolver;
use Throwable;

final class SocialAutomationServiceProvider extends ServiceProvider
{
    public const SLUG = 'social-automation';

    public function register(): void
    {
        $this->app->singleton(SocialAutomationServiceResolver::class);
        $this->app->singleton(SocialAutomationAccess::class);
        $this->app->singleton(SocialAutomationDatabaseConnectionService::class);
        $this->app->singleton(SocialAutomationDatabaseHealth::class);
        $this->app->singleton(SocialAutomationServiceHealth::class);
        $this->app->singleton(PackagePublishValidator::class);
        $this->app->singleton(WidgetInvalidationService::class);
        $this->app->singleton(ScriptCatalogService::class);
        $this->app->singleton(ScriptTombstoneService::class);
        $this->app->singleton(ScriptReleaseMediaService::class);
        $this->app->singleton(ScriptRemoveService::class);
        $this->app->singleton(ScriptRegistryUiService::class);
        $this->app->singleton(ScriptSyncPlanner::class);
        $this->app->singleton(WidgetWorkflowComposerService::class);
        $this->app->singleton(PackageRegistryService::class);
        $this->app->singleton(RpaAdminRoleGate::class);
        $this->app->singleton(RpaAdminSessionService::class);
        $this->app->singleton(OnlineStateCalculator::class);
        $this->app->singleton(EligibilitySelector::class);
        $this->app->singleton(JobStateMachine::class);
        $this->app->singleton(AgentRegistryService::class);
        $this->app->singleton(SessionSyncService::class);
        $this->app->singleton(JobOrchestrator::class);
        $this->app->singleton(ScheduleDispatcher::class);
        $this->app->singleton(DashboardMetrics::class);

        try {
            $this->registerCapabilities();
        } catch (Throwable) {
            // Capability registration failure must stay isolated to this addon.
        }
    }

    public function boot(): void
    {
        $root = dirname(__DIR__);
        $this->loadViewsFrom($root.'/resources/views', 'social-automation');
        $this->loadTranslationsFrom($root.'/resources/lang', 'social-automation');

        try {
            app(SocialAutomationServiceResolver::class)->ensureCatalogRow();
        } catch (Throwable) {
            // Keep other addons bootable; the Social Automation health UI reports the failure.
        }

        try {
            app(SocialAutomationDatabaseConnectionService::class)->bootstrap();
        } catch (Throwable) {
            // health endpoints report status
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SocialAutomationScheduleTickCommand::class]);
        }

        try {
            $this->registerWorkspaceDestination();
        } catch (Throwable) {
            // Workspace registration is optional and must not break unrelated panels.
        }
        $this->registerRoutes();
        $this->registerSeoPanelTopLevelNav();
    }

    private function registerWorkspaceDestination(): void
    {
        if (! $this->app->bound(WorkspaceDestinationRegistry::class)) {
            return;
        }

        /** @var WorkspaceDestinationRegistry $registry */
        $registry = $this->app->make(WorkspaceDestinationRegistry::class);
        if ($registry->has('social-automation')) {
            return;
        }

        $registry->register(new WorkspaceDestination(
            key: 'social-automation',
            label: 'Social Automation',
            url: url('/social-automation'),
            sort: 25,
            description: 'RPA Social Automation control plane',
            icon: 'heroicon-o-rectangle-group',
            panelId: 'social-automation',
            canAccess: static function (\App\Models\User $user): bool {
                if (! $user->canAccessPanel(\Filament\Facades\Filament::getPanel('social-automation'))) {
                    return false;
                }

                return app(SocialAutomationAccess::class)->canAccess($user);
            },
        ));
    }

    private function registerSeoPanelTopLevelNav(): void
    {
        Filament::serving(function (): void {
            $panelId = Filament::getCurrentPanel()?->getId();
            if (! in_array($panelId, ['seo', 'seo-main'], true)) {
                return;
            }

            try {
                if (! app(SocialAutomationAccess::class)->canAccess()) {
                    return;
                }
            } catch (Throwable) {
                return;
            }

            Filament::registerNavigationItems([
                NavigationItem::make('Social Automation')
                    ->icon('heroicon-o-rectangle-group')
                    ->url(url('/social-automation'))
                    ->sort(56)
                    ->isActiveWhen(static fn (): bool => request()->is('social-automation') || request()->is('social-automation/*')),
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
            ->prefix('api/social-automation')
            ->group(function (): void {
                Route::get('/bootstrap', SocialAutomationBootstrapController::class)
                    ->name('social-automation.bootstrap');
                Route::get('/health', SocialAutomationHealthController::class)
                    ->name('social-automation.health');
            });

        Route::middleware($middleware)
            ->prefix('social-automation')
            ->group(function (): void {
                Route::get('/script-media/{mediaId}', [ScriptReleaseMediaController::class, 'show'])
                    ->whereNumber('mediaId')
                    ->name('social-automation.script-media.show');
            });

        // RPA Admin user auth + Script sync (not Runner service_api_credentials).
        Route::prefix('api/v1/social-automation/rpa-admin')
            ->middleware([RenderRpaAdminRateLimitJson::class, 'throttle:60,1'])
            ->group(function (): void {
                Route::post('/login', [RpaAdminAuthController::class, 'login'])
                    ->name('social-automation.rpa-admin.login');

                Route::middleware([EnsureRpaAdminUserApi::class])->group(function (): void {
                    Route::get('/session', [RpaAdminAuthController::class, 'session'])
                        ->name('social-automation.rpa-admin.session');
                    Route::post('/logout', [RpaAdminAuthController::class, 'logout'])
                        ->name('social-automation.rpa-admin.logout');
                    Route::get('/scripts', [RpaAdminScriptSyncController::class, 'catalog'])
                        ->name('social-automation.rpa-admin.scripts.catalog');
                    Route::post('/scripts/sync/plan', [RpaAdminScriptSyncController::class, 'plan'])
                        ->name('social-automation.rpa-admin.scripts.sync.plan');
                    Route::post('/scripts/publish', [RpaAdminScriptSyncController::class, 'publish'])
                        ->name('social-automation.rpa-admin.scripts.publish');
                    Route::post('/scripts/pull', [RpaAdminScriptSyncController::class, 'pull'])
                        ->name('social-automation.rpa-admin.scripts.pull');
                    Route::post('/scripts/video-demo', [RpaAdminScriptSyncController::class, 'attachVideoDemo'])
                        ->name('social-automation.rpa-admin.scripts.video-demo');
                    Route::post('/scripts/asset', [RpaAdminScriptSyncController::class, 'attachAsset']);
                    Route::post('/backup', [RpaAdminScriptSyncController::class, 'backup']);
                    Route::get('/backup', [RpaAdminScriptSyncController::class, 'backupInfo']);
                    Route::post('/backup/download', [RpaAdminScriptSyncController::class, 'backupDownload']);
                    Route::get('/execution-reports', [RpaAdminScriptSyncController::class, 'reports']);
                    Route::post('/execution-reports', [RpaAdminScriptSyncController::class, 'storeReport']);
                });
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
            $caps->register($id, new SocialAutomationCapabilityMarker($id, self::SLUG), self::SLUG);
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

final class SocialAutomationCapabilityMarker
{
    public function __construct(
        public readonly string $id,
        public readonly string $ownerSlug,
    ) {}
}
