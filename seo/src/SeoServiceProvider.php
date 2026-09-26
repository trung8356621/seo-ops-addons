<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo;

use App\Core\Capability\CapabilityRegistry;
use App\Core\Members\MembersSectionRegistry;
use App\Core\Settings\SettingsSectionRegistry;
use Omnichannel\Addons\SearchFoundation\Members\SeoMembersSectionContributor;
use Omnichannel\Addons\Seo\Settings\SeoSettingsSectionContributor;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Illuminate\Support\ServiceProvider;

/**
 * Peer addon skeleton: registers capabilities into Client Core.
 * Implementation still migrating out of SeoContentAi legacy monolith.
 */
final class SeoServiceProvider extends ServiceProvider
{
    public const SLUG = 'seo';

    public function register(): void
    {
        $this->app->singleton(DomainContextResolver::class);
        $this->registerCapabilities();
        $this->app->singleton(SeoSettingsSectionContributor::class);
        $this->app->singleton(SeoMembersSectionContributor::class);
        $this->app->singleton(
            \Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpSourceRegistry::class,
            static function ($app): \Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpSourceRegistry {
                return new \Omnichannel\Addons\Seo\Services\MonthlyMcp\MonthlyMcpSourceRegistry([
                    $app->make(\Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources\SiteMonthlyMcpSource::class),
                    $app->make(\Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources\KeywordMonthlyMcpSource::class),
                    $app->make(\Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources\GscMonthlyMcpSource::class),
                ]);
            },
        );
        $this->app->bind(
            \Omnichannel\Addons\Seo\Services\GscContext\GscContextLoader::class,
            \Omnichannel\Addons\Seo\Services\GscContext\GscContextGateway::class,
        );
        $this->app->scoped(\Omnichannel\Addons\Seo\Services\GscContext\GscContextSource::class);
        $this->app->scoped(
            \Omnichannel\Addons\Seo\Services\Context\Registry\ContextRegistry::class,
            static function ($app): \Omnichannel\Addons\Seo\Services\Context\Registry\ContextRegistry {
                return new \Omnichannel\Addons\Seo\Services\Context\Registry\ContextRegistry([
                    $app->make(\Omnichannel\Addons\Seo\Services\Context\Providers\SiteHealthSliceProvider::class),
                    $app->make(\Omnichannel\Addons\Seo\Services\Context\Providers\SiteSyncSliceProvider::class),
                    $app->make(\Omnichannel\Addons\Seo\Services\Context\Providers\ContentInventorySliceProvider::class),
                    $app->make(\Omnichannel\Addons\Seo\Services\Context\Providers\ContentDistributionSliceProvider::class),
                    $app->make(\Omnichannel\Addons\Seo\Services\Context\Providers\SeoFindingsSliceProvider::class),
                    $app->make(\Omnichannel\Addons\Seo\Services\Context\Providers\SeoInternalLinksSliceProvider::class),
                    $app->make(\Omnichannel\Addons\Seo\Services\Context\Providers\PublishingStatusSliceProvider::class),
                    $app->make(\Omnichannel\Addons\Seo\Services\Context\Providers\KeywordsLandscapeSliceProvider::class),
                    $app->make(\Omnichannel\Addons\Seo\Services\Context\Providers\KeywordsRelationshipSliceProvider::class),
                    $app->make(\Omnichannel\Addons\Seo\Services\Context\Providers\GscPerformanceSliceProvider::class),
                    $app->make(\Omnichannel\Addons\Seo\Services\Context\Providers\GscOpportunitiesSliceProvider::class),
                    $app->make(\Omnichannel\Addons\Seo\Services\Context\Providers\GscCannibalizationSliceProvider::class),
                ]);
            },
        );
        $this->app->scoped(
            \Omnichannel\Addons\Seo\Services\Mcp\Router\McpRouterRegistry::class,
            static function ($app): \Omnichannel\Addons\Seo\Services\Mcp\Router\McpRouterRegistry {
                return \Omnichannel\Addons\Seo\Services\Mcp\Catalog\SeoMcpRouterCatalog::build(
                    $app->make(\Omnichannel\Addons\Seo\Services\Context\Registry\ContextRegistry::class),
                );
            },
        );
        $this->app->scoped(\Omnichannel\Addons\Seo\Services\Mcp\Router\McpRouterReader::class);
        $this->app->scoped(\Omnichannel\Addons\Seo\Services\Mcp\Manifest\McpManifestMarkdownPresenter::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(dirname(__DIR__).'/resources/views', 'seo');

        $this->registerAddonPermissions();

        // Members capacity contributor must register whenever SEO boots (Admin panel included).
        // SearchFoundation alone is not register_early — do not rely on it for Admin requests.
        if ($this->app->bound(MembersSectionRegistry::class)) {
            $members = $this->app->make(MembersSectionRegistry::class);
            $contributor = $this->app->make(SeoMembersSectionContributor::class);
            if (! $members->has($contributor->addonSlug())) {
                $members->register($contributor);
            }
        }

        if ($this->app->bound(SettingsSectionRegistry::class)) {
            $settings = $this->app->make(SettingsSectionRegistry::class);
            if (! $settings->hasContributor('seo')) {
                $settings->register($this->app->make(SeoSettingsSectionContributor::class));
            }
        }

        $this->registerWorkspaceDestination();
        $this->registerOperationalLandingDashboardStyles();
    }

    private function registerOperationalLandingDashboardStyles(): void
    {
        if (! class_exists(\Filament\Support\Facades\FilamentView::class)
            || ! class_exists(\Filament\View\PanelsRenderHook::class)
        ) {
            return;
        }

        // Load CSS in panel <head> — never via @vite inside Livewire widget Blade
        // (that breaks the Livewire DOM tree and blanks the Dashboard).
        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::STYLES_AFTER,
            static function (): string {
                try {
                    $panelId = \Filament\Facades\Filament::getCurrentPanel()?->getId();
                    if (! in_array($panelId, ['seo', 'seo-main'], true)) {
                        return '';
                    }

                    return (string) app(\Illuminate\Foundation\Vite::class)([
                        'addons/seo/resources/css/operational-landing-dashboard.css',
                        'addons/seo/resources/css/ops-statistics.css',
                    ]);
                } catch (\Throwable) {
                    return '';
                }
            },
        );
    }

    private function registerWorkspaceDestination(): void
    {
        if (! $this->app->bound(\App\Core\Workspace\WorkspaceDestinationRegistry::class)) {
            return;
        }

        /** @var \App\Core\Workspace\WorkspaceDestinationRegistry $registry */
        $registry = $this->app->make(\App\Core\Workspace\WorkspaceDestinationRegistry::class);
        if ($registry->has('seo')) {
            return;
        }

        $registry->register(new \App\Core\Workspace\WorkspaceDestination(
            key: 'seo',
            label: 'SEO',
            url: url('/seo'),
            sort: 10,
            description: 'Nội dung, dự án và tối ưu SEO',
            icon: 'heroicon-o-magnifying-glass',
            panelId: 'seo',
        ));
    }

    private function registerAddonPermissions(): void
    {
        if (! $this->app->bound(\App\Core\Permissions\AddonPermissionRegistry::class)) {
            return;
        }

        /** @var \App\Core\Permissions\AddonPermissionRegistry $registry */
        $registry = $this->app->make(\App\Core\Permissions\AddonPermissionRegistry::class);
        $registry->register(self::SLUG, [
            \App\Core\Permissions\LegacySeoRoleBridge::ROLE_MANAGER,
            \App\Core\Permissions\LegacySeoRoleBridge::ROLE_PLANNER,
            \App\Core\Permissions\LegacySeoRoleBridge::ROLE_CONTENT_MANAGER,
        ]);

        try {
            $registry->ensureSynced();
        } catch (\Throwable) {
            // Tables may not exist until migrate.
        }
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
