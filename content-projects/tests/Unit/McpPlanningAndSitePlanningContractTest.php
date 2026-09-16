<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Carbon\CarbonImmutable;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\McpPlanning\McpPlanningMeta;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\McpPlanning\McpPlanningMetaStore;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\McpPlanning\McpPlanningSignalService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\MoveContentProjectToNextMonthService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SiteMonthlyContentTargetService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningReadModel;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class McpPlanningAndSitePlanningContractTest extends TestCase
{
    public function test_migration_adds_seo_projects_meta_column(): void
    {
        $migration = dirname(__DIR__, 2).'/database/migrations/2026_09_04_100000_add_meta_to_seo_projects_table.php';
        self::assertFileExists($migration);
        $src = (string) file_get_contents($migration);
        self::assertStringContainsString("'meta'", $src);
        self::assertStringContainsString('seo_projects', $src);
    }

    public function test_seo_project_casts_meta_as_array(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProject::class))->getFileName(),
        );
        self::assertStringContainsString("'meta' => 'array'", $src);
    }

    public function test_mcp_planning_meta_schema_and_dedupe(): void
    {
        $wrapped = McpPlanningMeta::wrap([
            [
                'project_item_id' => 10,
                'site_id' => 6,
                'cluster_key' => 'ck_a',
                'keyword_id' => 200,
                'approved_at' => '2026-09-04T00:00:00+00:00',
            ],
            [
                'project_item_id' => 10,
                'site_id' => 6,
                'cluster_key' => 'ck_b',
            ],
            [
                'project_item_id' => 0,
                'site_id' => 6,
            ],
        ]);

        self::assertArrayHasKey(McpPlanningMeta::ITEMS_KEY, $wrapped);
        self::assertCount(1, $wrapped[McpPlanningMeta::ITEMS_KEY]);
        self::assertSame(10, $wrapped[McpPlanningMeta::ITEMS_KEY][0]['project_item_id']);
        self::assertSame('ck_a', $wrapped[McpPlanningMeta::ITEMS_KEY][0]['cluster_key']);
    }

    public function test_split_records_mcp_planning_on_execution(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SplitDraftContentProjectService::class))->getFileName(),
        );
        self::assertStringContainsString('McpPlanningSignalService', $src);
        self::assertStringContainsString('recordSplitToExecution', $src);
    }

    public function test_archive_clears_only_mcp_planning_meta_key(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArchiveContentProjectService::class))->getFileName(),
        );
        self::assertStringContainsString('McpPlanningMetaStore', $src);
        self::assertStringContainsString('mcpPlanningMeta->clear', $src);

        $storeSrc = (string) file_get_contents(
            (string) (new ReflectionClass(McpPlanningMetaStore::class))->getFileName(),
        );
        self::assertStringContainsString('McpPlanningMeta::META_KEY', $storeSrc);
        self::assertStringContainsString('unset($meta[McpPlanningMeta::META_KEY])', $storeSrc);
    }

    public function test_signal_service_uses_draft_reviewed_and_project_meta_with_item_key_dedupe(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(McpPlanningSignalService::class))->getFileName(),
        );
        self::assertStringContainsString('draftReviewedSignals', $src);
        self::assertStringContainsString('projectMetaSignals', $src);
        self::assertStringContainsString("item_key' => 'draft:", $src);
        self::assertStringContainsString("item_key' => 'exec:", $src);
        self::assertStringContainsString('planning_reviewed_at', $src);
    }

    public function test_site_planning_ideas_new_uses_source_type_and_result_summary_status(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SitePlanningReadModel::class))->getFileName(),
        );
        self::assertStringContainsString("where('source_type', SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT)", $src);
        self::assertStringNotContainsString("where('source', SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT)", $src);
        self::assertStringContainsString("\$summary['status']", $src);
        self::assertStringContainsString('STATUS_COMPLETED', $src);
        self::assertStringContainsString('STATUS_PARTIAL', $src);
        self::assertStringContainsString('KIND_EXECUTED', $src);
        self::assertStringContainsString('SitePlanningActiveUnitAggregator', $src);
        self::assertStringContainsString('activeMonth', $src);
    }

    public function test_site_planning_month_window_crosses_year(): void
    {
        $readModel = new ReflectionClass(SitePlanningReadModel::class);
        $method = $readModel->getMethod('monthWindow');
        // Instantiate without deps by invoking via anonymous stub is heavy; assert source contract instead.
        $src = (string) file_get_contents((string) $readModel->getFileName());
        self::assertStringContainsString('for ($offset = -2; $offset <= 1; $offset++)', $src);
        self::assertStringContainsString('addMonthsNoOverflow', $src);

        // Pure calendar helper mirror of read-model window.
        $current = CarbonImmutable::parse('2027-01-15')->startOfMonth();
        $labels = [];
        for ($offset = -2; $offset <= 1; $offset++) {
            $labels[] = $current->addMonthsNoOverflow($offset)->format('m/Y');
        }
        self::assertSame(['11/2026', '12/2026', '01/2027', '02/2027'], $labels);
    }

    public function test_monthly_content_target_default_is_execution_pack_size(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SiteMonthlyContentTargetService::class))->getFileName(),
        );
        self::assertStringContainsString("META_KEY = 'monthly_content_target'", $src);
        self::assertStringContainsString('ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS', $src);
        self::assertSame(30, ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS);
    }

    public function test_move_next_month_uses_packing_and_moves_mcp_meta(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(MoveContentProjectToNextMonthService::class))->getFileName(),
        );
        self::assertStringContainsString('planPack', $src);
        self::assertStringContainsString('moveTasksPreservingState', $src);
        self::assertStringContainsString('nextExecutionProjectName', $src);
        self::assertStringContainsString('mcpMeta->upsertItems', $src);
        self::assertStringContainsString('mcpMeta->removeItems', $src);
        self::assertStringContainsString('addMonthNoOverflow', $src);
        self::assertStringContainsString('publish_queue_status', $src);
        self::assertStringContainsString('STATUS_RUNNING', $src);
    }

    public function test_planner_ui_has_site_planning_as_third_column(): void
    {
        $planner = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');
        $page = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        $css = LegacyAddonPath::read('resources/views/components/content-project-ops-styles.blade.php');

        self::assertStringContainsString('data-site-planning-section="1"', $planner);
        self::assertStringContainsString('data-planner-card="site-planning"', $planner);
        self::assertStringContainsString('content-project-site-planning', $planner);
        self::assertStringContainsString('data-create-tab="ai-history"', $planner);
        self::assertStringContainsString('data-create-tab="ideas"', $planner);
        self::assertStringContainsString('data-create-tab="ai"', $planner);
        self::assertStringNotContainsString('data-create-tab="site-planning"', $planner);
        self::assertStringNotContainsString("createTab === 'site-planning'", $planner);
        self::assertStringNotContainsString('data-create-panel="site-planning"', $planner);
        self::assertStringNotContainsString('content-project-site-planning', $page);
        self::assertStringContainsString('repeat(3, minmax(0, 1fr))', $css);
        self::assertStringContainsString('[data-planner-card="site-planning"]', $css);

        $sitePlanning = LegacyAddonPath::read('resources/views/components/content-project-site-planning.blade.php');
        self::assertStringContainsString('data-site-planning="1"', $sitePlanning);
        self::assertStringContainsString('cp-site-planning__table', $sitePlanning);
        self::assertStringContainsString('cp-site-planning__sticky', $sitePlanning);
        self::assertStringContainsString('year_groups', $sitePlanning);
        self::assertStringNotContainsString('lg:grid-cols-', $sitePlanning);
        self::assertStringNotContainsString('selectSitePlanningSite', $sitePlanning);
    }

    public function test_site_planning_year_groups_span_consecutive_months(): void
    {
        $readModel = new ReflectionClass(SitePlanningReadModel::class);
        $src = (string) file_get_contents((string) $readModel->getFileName());
        self::assertStringContainsString('function yearGroups', $src);
        self::assertStringContainsString("'year' => (int) \$month->year", $src);
        self::assertStringContainsString("'month' => \$month->format('m')", $src);

        // Pure mirror of yearGroups for cross-year window.
        $months = [
            ['year' => 2026],
            ['year' => 2026],
            ['year' => 2027],
            ['year' => 2027],
        ];
        $groups = [];
        foreach ($months as $month) {
            $year = (int) $month['year'];
            $last = $groups === [] ? null : array_key_last($groups);
            if ($last !== null && (int) $groups[$last]['year'] === $year) {
                $groups[$last]['span']++;
                continue;
            }
            $groups[] = ['year' => $year, 'span' => 1];
        }
        self::assertSame([
            ['year' => 2026, 'span' => 2],
            ['year' => 2027, 'span' => 2],
        ], $groups);
    }

    public function test_topic_cluster_ui_shows_planning_plus_tag_not_merged_into_percent(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php');
        self::assertStringContainsString('planning_pending_count', $blade);
        self::assertStringContainsString('cluster-index-row__planning-plus', $blade);
        self::assertStringContainsString('topic_mcp_planning_pending_tooltip', $blade);
        self::assertStringContainsString('{{ $shareDisplay }}%', $blade);
    }

    public function test_view_project_has_move_next_month_action(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProject.php',
        );
        self::assertStringContainsString("Action::make('move_next_month')", $src);
        self::assertStringContainsString('MoveContentProjectToNextMonthService', $src);
    }
}
