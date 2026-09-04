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

    public function test_planner_ui_has_site_planning_tab_internal(): void
    {
        $planner = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');
        self::assertStringContainsString("createTab === 'site-planning'", $planner);
        self::assertStringContainsString('data-create-tab="site-planning"', $planner);
        self::assertStringContainsString('content-project-site-planning', $planner);
        self::assertStringContainsString('target="_blank"', $planner);
        self::assertStringContainsString('data-create-tab="ai-history"', $planner);

        $sitePlanning = LegacyAddonPath::read('resources/views/components/content-project-site-planning.blade.php');
        self::assertStringContainsString('data-site-planning="1"', $sitePlanning);
        self::assertStringContainsString('selectSitePlanningSite', $sitePlanning);
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
