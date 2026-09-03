<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\Seo\Support\DomainContext;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoPanelRoutes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Project Planner uses Global Domain as Working Site SSOT + real scroll-owner snap.
 */
final class PlannerGlobalDomainWorkspaceContractTest extends TestCase
{
    public function test_global_picker_shows_on_planner_not_project_list(): void
    {
        $access = (string) file_get_contents(
            (string) (new ReflectionClass(SeoAccessControl::class))->getFileName(),
        );

        self::assertStringContainsString('isProjectPlannerSeoAuditPage', $access);
        self::assertStringContainsString('shouldRequireConcreteGlobalDomain', $access);
        self::assertTrue(method_exists(SeoPanelRoutes::class, 'isProjectPlannerSeoAudit'));
        self::assertTrue(method_exists(SeoPanelRoutes::class, 'isProjectPlannerSeoAuditPath'));
    }

    public function test_planner_binds_working_site_from_global_domain(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringContainsString('applyWorkingSiteContext', $page);
        self::assertStringContainsString('migrateLegacyPlannerSiteQuery', $page);
        self::assertStringContainsString('ensureConcreteGlobalWorkingSite', $page);
        self::assertStringContainsString('onDomainContextChanged', $page);
        self::assertStringContainsString('DomainContext::SITE_ID_QUERY_KEY', $page);
        self::assertStringNotContainsString("#[Url(as: 'site')]", $page);
        self::assertStringContainsString('HidesFilamentPageHeader', $page);
    }

    public function test_no_local_working_site_toolbar(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');

        self::assertStringNotContainsString('wire:model.live="filterSiteId"', $blade);
        self::assertStringNotContainsString('content_planning_working_site', $blade);
        self::assertStringNotContainsString('cp-plan-slide__toolbar', $blade);
        self::assertStringNotContainsString('data-content-planning-context', $blade);
        self::assertStringContainsString('content-project-draft-planner', $blade);
        self::assertStringContainsString('cp-plan-slide--create', $blade);
        self::assertStringContainsString('cp-plan-slide--draft', $blade);
    }

    public function test_snap_attaches_to_detected_scroll_owner(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        $styles = LegacyAddonPath::read('resources/views/components/content-project-ops-styles.blade.php');

        self::assertStringContainsString('cp-plan-snap-owner', $blade);
        self::assertStringContainsString('findScrollOwner', $blade);
        self::assertStringContainsString('attachSnapOwner', $blade);
        self::assertStringContainsString('detachSnapOwner', $blade);
        self::assertStringContainsString('.cp-plan-snap-owner', $styles);
        self::assertStringContainsString('scroll-snap-type: y mandatory', $styles);
        self::assertStringNotContainsString('.fi-main:has(.cp-plan-scroll-workspace)', $styles);
        self::assertStringNotContainsString('scroll-snap-type: y proximity', $styles);
        self::assertStringNotContainsString('preventDefault()', $blade);
        self::assertStringNotContainsString('@wheel', $blade);
    }

    public function test_global_bar_hides_all_domains_on_planner(): void
    {
        $bar = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\Seo\Livewire\GlobalSeoBar::class))->getFileName(),
        );
        $view = (string) file_get_contents(
            dirname((string) (new ReflectionClass(\Omnichannel\Addons\Seo\Livewire\GlobalSeoBar::class))->getFileName(), 3)
            .'/resources/views/livewire/global-seo-bar.blade.php',
        );

        self::assertStringContainsString('hideAllDomainsOption', $bar);
        self::assertStringContainsString('isProjectPlannerSeoAudit', $bar);
        self::assertStringContainsString('hideAllDomainsOption', $view);
        self::assertSame('site_id', DomainContext::SITE_ID_QUERY_KEY);
    }
}
