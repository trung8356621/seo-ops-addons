<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Project Planner scroll-snap two-section workspace layout contracts.
 */
final class PlannerScrollSnapLayoutContractTest extends TestCase
{
    public function test_page_has_two_primary_slides_with_scroll_workspace(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        $styles = LegacyAddonPath::read('resources/views/components/content-project-ops-styles.blade.php');

        self::assertStringContainsString('cp-plan-scroll-workspace', $blade);
        self::assertStringContainsString('id="planner-create"', $blade);
        self::assertStringContainsString('id="planner-draft"', $blade);
        self::assertStringContainsString('cp-plan-slide cp-plan-slide--create', $blade);
        self::assertStringContainsString('cp-plan-slide cp-plan-slide--draft', $blade);
        self::assertStringContainsString('cp-plan-snap-owner', $styles);
        self::assertStringContainsString('scroll-snap-type: y mandatory', $styles);
        self::assertStringContainsString('scroll-snap-align: start', $styles);
    }

    public function test_working_site_comes_from_global_bar_not_local_toolbar(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        $draft = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');

        self::assertStringNotContainsString('wire:model.live="filterSiteId"', $blade);
        self::assertStringNotContainsString('content_planning_working_site', $blade);
        self::assertStringNotContainsString('cp-plan-slide__toolbar', $blade);
        self::assertStringNotContainsString('wire:model.live="filterSiteId"', $draft);
    }

    public function test_publish_moved_to_draft_section_header(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString(':show-publish-in-header="true"', $blade);
        self::assertStringContainsString('openPublishFromPlanner', $items);
        self::assertStringContainsString('data-content-planning-action="publish"', $items);
        self::assertStringContainsString('cp-plan-draft-header', $items);
        self::assertStringNotContainsString('openPublishFromPlanner', $blade);
    }

    public function test_no_redundant_subtitle_or_planning_draft_readonly_field(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');

        self::assertStringNotContainsString('data-content-planning-subtitle', $blade);
        self::assertStringNotContainsString('content_planning_draft_label', $blade);
        self::assertStringNotContainsString('data-planning-draft-display', $blade);
        self::assertStringNotContainsString('cp-plan-context', $blade);
    }

    public function test_improve_and_ai_cards_in_section_one(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        $draft = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');

        self::assertStringContainsString('content-project-draft-planner', $blade);
        self::assertStringContainsString('data-planner-card="improve"', $draft);
        self::assertStringContainsString('content-project-new-content-card', $draft);
        self::assertStringContainsString('cp-plan-slide__grid', $draft);
    }

    public function test_draft_table_in_section_two_with_section_jumps(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');

        self::assertStringContainsString('content-project-draft-items', $blade);
        self::assertStringContainsString('cp-plan-section-jump', $blade);
        self::assertStringContainsString('cpPlanScrollWorkspace', $blade);
        self::assertStringContainsString('jumpToDraft()', $blade);
        self::assertStringContainsString('jumpToCreate()', $blade);
        self::assertStringContainsString('prefers-reduced-motion', $blade);
        self::assertStringContainsString('findScrollOwner', $blade);
    }

    public function test_no_wheel_scrolljacking(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        $draft = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');
        $combined = $blade.$draft.$items;

        self::assertStringNotContainsString('preventDefault()', $combined);
        self::assertStringNotContainsString('@wheel', $combined);
        self::assertStringNotContainsString('wheel.prevent', $combined);
    }

    public function test_section_one_capped_to_one_viewport(): void
    {
        $styles = LegacyAddonPath::read('resources/views/components/content-project-ops-styles.blade.php');

        self::assertStringContainsString('max-height: calc(100dvh - var(--cp-plan-top-offset))', $styles);
        self::assertStringContainsString('overflow: hidden', $styles);
        self::assertStringNotContainsString('min(32rem', $styles);
    }

    public function test_responsive_snap_disabled_on_mobile(): void
    {
        $styles = LegacyAddonPath::read('resources/views/components/content-project-ops-styles.blade.php');

        self::assertMatchesRegularExpression(
            '/@media \(max-width: 1023px\)[\s\S]*?\.cp-plan-snap-owner[\s\S]*?scroll-snap-type: none/',
            $styles,
        );
    }

    public function test_url_state_uses_global_site_id_and_draft_domain(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');

        self::assertStringNotContainsString("#[Url(as: 'site')]", $page);
        self::assertStringContainsString("#[Url(as: 'draft_domain', except: 'all')]", $page);
        self::assertStringContainsString('SITE_ID_QUERY_KEY', $page);
        self::assertStringNotContainsString('wire:model.live="filterSiteId"', $blade);
        self::assertStringContainsString(':draft-domain-filter="$this->draftDomainFilter"', $blade);
    }
}
