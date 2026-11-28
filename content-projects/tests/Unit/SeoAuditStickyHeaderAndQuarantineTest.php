<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditSuggestionDecisionService;
use Omnichannel\Addons\Seo\Livewire\GlobalSeoBar;
use ReflectionClass;
use Tests\TestCase;

final class SeoAuditStickyHeaderAndQuarantineTest extends TestCase
{
    public function test_global_seo_bar_hides_domain_picker_on_seo_audit_page_only(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalSeoBar::class))->getFileName(),
        );

        self::assertStringContainsString('$this->showPlannerActiveMonth = SeoAccessControl::isProjectPlannerSeoAuditPage();', $src);
        self::assertStringContainsString('|| $this->showPlannerActiveMonth;', $src);
        self::assertStringContainsString('$this->bootstrapDatabaseForCurrentSite();', $src);
    }

    public function test_decision_service_exposes_site_scoped_quarantine_count_and_clear_with_legacy_null_site_support(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoAuditSuggestionDecisionService::class))->getFileName(),
        );

        self::assertStringContainsString('function countDismissedForSite(', $src);
        self::assertStringContainsString('function clearDismissedForSite(', $src);
        self::assertStringContainsString('SeoContentProjectSuggestionDecision::DECISION_DISMISSED', $src);
        self::assertStringContainsString("->whereNull('site_id')", $src);
        self::assertStringContainsString("->whereIn('article_id', SeoArticle::query()->select('id')->where('site_id', \$siteId))", $src);
    }

    public function test_planner_quarantine_requires_concrete_domain_and_never_touches_tasks_or_articles(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringContainsString('function getDraftQuarantineStateProperty()', $src);
        self::assertStringContainsString('function clearDraftQuarantine()', $src);
        self::assertStringContainsString("\$filter === 'all' || \$filter === '0'", $src);
        self::assertStringContainsString('clearDismissedForSite($draft, $siteId)', $src);
    }

    public function test_seo_audit_blade_has_single_sticky_draft_header_with_domain_quarantine_and_publish(): void
    {
        $addonsRoot = dirname(__DIR__, 3);
        $blade = (string) file_get_contents(
            $addonsRoot.'/seo-content-ai-compat/resources/views/filament/pages/content-project-seo-audit-planner.blade.php',
        );
        $styles = (string) file_get_contents(
            $addonsRoot.'/seo-content-ai-compat/resources/views/components/content-project-ops-styles.blade.php',
        );

        self::assertStringContainsString('data-draft-sticky-header="1"', $blade);
        self::assertStringContainsString('data-draft-domain-filter="1"', $blade);
        self::assertStringContainsString('data-content-planning-action="clear-quarantine"', $blade);
        self::assertStringContainsString('data-content-planning-action="publish"', $blade);
        self::assertStringContainsString(':hide-section-title="true"', $blade);
        self::assertStringContainsString(':show-publish-in-header="false"', $blade);

        // Sticky bar sits below Filament topbar (4rem) without high z-index hacks.
        self::assertStringContainsString('.cp-plan-draft-sticky-bar', $styles);
        self::assertStringContainsString('position: sticky;', $styles);
        self::assertStringContainsString('top: 4rem;', $styles);
        self::assertStringContainsString('z-index: 15;', $styles);
    }
}
