<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectIdeaManager;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaManagerActiveMonthPreference;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningReadModel;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Phase 2 — Active Month, two-column layout, Site Planning reuse, distribution BLOCKED.
 */
final class IdeaManagerPhase2ContractTest extends TestCase
{
    public function test_active_month_preference_meta_key_and_default(): void
    {
        self::assertSame('idea_manager_active_month', IdeaManagerActiveMonthPreference::META_KEY);
        $opts = IdeaManagerActiveMonthPreference::options(2, 1);
        self::assertNotEmpty($opts);
        self::assertArrayHasKey('value', $opts[0]);
        self::assertSame(ContentProjectMonthContext::current(), ContentProjectMonthContext::normalize(null));
    }

    public function test_idea_manager_wires_active_month_and_target_independently(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectIdeaManager::class))->getFileName()
        );
        self::assertStringContainsString('IdeaManagerActiveMonthPreference', $src);
        self::assertStringContainsString('setActiveMonth', $src);
        self::assertStringContainsString('selectIdeaManagerTargetSite', $src);
        self::assertStringContainsString('targetSiteId', $src);
        self::assertStringContainsString('sourceFilterSiteId', $src);
        self::assertStringContainsString('sitePlanningPayload', $src);
        self::assertStringContainsString('sitePlanningCellDetail', $src);
        self::assertStringContainsString('SitePlanningReadModel', $src);
        self::assertStringContainsString('distributionContext', $src);
        self::assertStringContainsString('previewDistribution', $src);
        self::assertStringContainsString('confirmDistribution', $src);
        self::assertStringContainsString('IdeaManagerDistributionService', $src);
        self::assertStringContainsString("'contract' => 'destination_idea_workspace'", $src);
        // Source change must not clear an explicit target.
        self::assertStringContainsString('never overwrites an explicit target', $src);
        // Active Month must not mutate item planning_month.
        self::assertStringContainsString('do not touch workspaces or planning_month', $src);
        self::assertStringNotContainsString("forceFill(['planning_month'", $src);
    }

    public function test_source_filter_does_not_remount_warehouse_or_clear_working_site(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectIdeaManager::class))->getFileName()
        );
        // setSourceSite must only touch sourceFilterSiteId + clusters.
        self::assertMatchesRegularExpression(
            '/function setSourceSite\(int \$siteId\): void\s*\{[\s\S]*?sourceFilterSiteId = \$siteId;[\s\S]*?loadClusterSuggestions\(\);[\s\S]*?idea-manager-source-filter-changed/',
            $src,
        );
        $setSource = self::extractMethod($src, 'setSourceSite');
        self::assertStringNotContainsString('filterSiteId = $siteId', $setSource);
        self::assertStringNotContainsString('refreshWorkspaceFromServer', $setSource);
        self::assertStringNotContainsString('idea-manager-site-changed', $setSource);
        self::assertStringContainsString('idea-manager-source-filter-changed', $setSource);

        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');
        self::assertStringContainsString('onSourceFilterChanged', $blade);
        self::assertStringContainsString('ideaProvenanceDomain', $blade);
        self::assertStringContainsString('sourceFilterSiteId', $blade);
        self::assertStringContainsString('idea-manager-source-filter-changed.window', $blade);
        // Badge must not bind every row to live working/filter domain.
        self::assertStringNotContainsString(
            "replace('__D__', siteDomain || ('#' + siteId))",
            $blade,
        );
        self::assertStringContainsString('ideaProvenanceDomain(item)', $blade);
    }

    public function test_active_month_does_not_write_planning_month_on_items(): void
    {
        $pref = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaManagerActiveMonthPreference::class))->getFileName()
        );
        self::assertStringContainsString('Does not mutate Content Project Item planning_month', $pref);
        self::assertStringNotContainsString('seo_project_tasks', $pref);
        self::assertStringContainsString('setMeta', $pref);
        self::assertStringContainsString('getMeta', $pref);
        self::assertStringContainsString('META_KEY', $pref);
    }

    public function test_blade_two_column_sidebar_and_site_planning_reuse(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');
        self::assertStringContainsString('data-im-step1', $blade);
        self::assertStringContainsString('data-im-sidebar', $blade);
        self::assertStringContainsString('data-im-warehouse', $blade);
        self::assertStringContainsString('data-im-planning', $blade);
        self::assertStringContainsString('data-im-distribution', $blade);
        self::assertStringContainsString('wire:model.live="activeMonth"', $blade);
        self::assertStringContainsString('setSourceSite', $blade);
        self::assertStringContainsString('content-project-site-planning', $blade);
        self::assertStringContainsString('toggleSelect', $blade);
        self::assertStringContainsString('selectedCount', $blade);
        self::assertStringContainsString('ideaManagerDistribution', $blade);
        self::assertStringContainsString('previewDistribution', $blade);
        self::assertStringContainsString('confirmDistribution', $blade);
        self::assertStringContainsString('data-im-distribution-confirm', $blade);
        self::assertStringContainsString('idea_manager_distribution_exact', $blade);
        self::assertStringContainsString('idea_manager_distribution_warn', $blade);
        // Step 2 preview must not show source-domain text after Idea names.
        self::assertStringNotContainsString("x-text=\"' · ' + (it.source_domain || '')\"", $blade);
        self::assertStringContainsString(':disabled="!canConfirmNow()"', $blade);
        self::assertStringContainsString('displayItems', $blade);
        self::assertStringContainsString('wire:ignore', $blade);
        // Warehouse stays wire:ignore so Site Planning Livewire refresh cannot wipe draft.
        self::assertMatchesRegularExpression('/data-im-warehouse[\s\S]*?wire:ignore/', $blade);
        // No dedicated Cluster Notes tab when sidebar replaces it.
        self::assertStringNotContainsString("setTab('cluster')", $blade);
        // Autosave contracts preserved.
        self::assertStringContainsString('DEBOUNCE_MS = 3000', $blade);
        self::assertStringContainsString('MAX_SYNC_MS = 30000', $blade);
        self::assertStringContainsString('confirmKeepMine', $blade);
        self::assertStringContainsString('confirmLoadServer', $blade);
    }

    public function test_site_planning_component_supports_optional_target_select_without_duplicating_counts(): void
    {
        $sp = LegacyAddonPath::read('resources/views/components/content-project-site-planning.blade.php');
        self::assertStringContainsString('sitePlanningPayload', $sp);
        self::assertStringContainsString('selectIdeaManagerTargetSite', $sp);
        self::assertStringContainsString('is-target', $sp);
        // Still uses planned/target from payload — no local recount formula.
        self::assertStringContainsString('$planned }} / {{ $target', $sp);
        self::assertStringNotContainsString('SitePlanningActiveUnitAggregator', $sp);

        $read = (string) file_get_contents(
            (string) (new ReflectionClass(SitePlanningReadModel::class))->getFileName()
        );
        self::assertStringContainsString('SitePlanningActiveUnitAggregator', $read);
    }

    public function test_seo_audit_still_untouched(): void
    {
        $blade = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');
        self::assertStringContainsString('seoOps:content-planner:audit-notes:v2:site:', $blade);
        self::assertStringNotContainsString('ideaManagerRoot', $blade);
        self::assertStringNotContainsString('idea_manager_active_month', $blade);
    }

    public function test_save_still_scoped_to_source_workspace_not_cross_site_dump(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectIdeaManager::class))->getFileName()
        );
        self::assertStringContainsString('abort_unless($siteId === (int) ($this->filterSiteId ?? 0), 403)', $src);
        self::assertStringNotContainsString('note_items from all sites', $src);
    }

    public function test_phase2_lang_keys(): void
    {
        $en = LegacyAddonPath::read('lang/en/filament.php');
        $vi = LegacyAddonPath::read('lang/vi/filament.php');
        foreach ([
            'idea_manager_active_month',
            'idea_manager_distribution_blocked',
            'idea_manager_warehouse_title',
            'idea_manager_sidebar_title',
        ] as $key) {
            self::assertStringContainsString("'{$key}'", $en);
            self::assertStringContainsString("'{$key}'", $vi);
        }
    }

    public function test_keyword_drawer_routes_to_idea_manager_while_planning_draft_service_remains(): void
    {
        $drawer = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\Content\Livewire\AssignToContentProjectDrawer::class))->getFileName()
        );
        self::assertStringContainsString('IdeaManagerKeywordIntakeService', $drawer);
        self::assertStringContainsString('PlanningDraftIntakeService', $drawer);
        self::assertStringContainsString('->addArticles(', $drawer);
        self::assertStringContainsString('->addPendingLink(', $drawer);

        $intake = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeService::class
            ))->getFileName()
        );
        self::assertStringContainsString('assignKeywords', $intake);
        self::assertStringNotContainsString('seo_content_project_idea_workspaces', $intake);
    }

    private static function extractMethod(string $src, string $name): string
    {
        if (! preg_match('/function '.$name.'\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $start = (int) $m[0][1];
        $i = strpos($src, '{', $start);
        if ($i === false) {
            return '';
        }
        $depth = 0;
        $len = strlen($src);
        for ($p = $i; $p < $len; $p++) {
            $ch = $src[$p];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $p - $start + 1);
                }
            }
        }

        return '';
    }
}
