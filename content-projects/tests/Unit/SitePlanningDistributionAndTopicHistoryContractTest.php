<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectTopicHistory;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteClusterSuggestionQuery;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningActiveUnitAggregator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\TopicHistoryReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\TopicHistoryWriter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Contract: Site Planning = article distribution; Month Detail = Topic History only.
 */
final class SitePlanningDistributionAndTopicHistoryContractTest extends TestCase
{
    public function test_matrix_counts_distribution_not_active_predicate(): void
    {
        $agg = (string) file_get_contents(
            (string) (new ReflectionClass(SitePlanningActiveUnitAggregator::class))->getFileName(),
        );
        $read = (string) file_get_contents(
            (string) (new ReflectionClass(SitePlanningReadModel::class))->getFileName(),
        );

        self::assertStringContainsString('STATUS_CANCELLED', $agg);
        self::assertStringContainsString('whereNull(\'t.deleted_at\')', $agg);
        self::assertStringNotContainsString('SitePlanningActiveUnitPredicate', $agg);
        self::assertStringNotContainsString('publish_published_at', $agg);
        self::assertStringNotContainsString('STATUS_COMPLETED', $agg);
        self::assertStringContainsString('TopicHistoryReadModel', $read);
        self::assertStringNotContainsString('SitePlanningMonthCoverageReadModel', $read);
        self::assertFileDoesNotExist(
            dirname(__DIR__, 2).'/src/Services/ContentProject/SitePlanning/SitePlanningActiveUnitPredicate.php',
        );
        self::assertFileDoesNotExist(
            dirname(__DIR__, 2).'/src/Services/ContentProject/SitePlanning/SitePlanningMonthCoverageReadModel.php',
        );
        self::assertFileDoesNotExist(
            dirname(__DIR__, 2).'/src/Services/ContentProject/SitePlanning/ContentProjectClusterCoverageIndex.php',
        );
    }

    public function test_topic_history_schema_and_writer_boundary(): void
    {
        $migration = dirname(__DIR__, 2).'/database/migrations/2026_09_16_110000_create_seo_content_project_topic_histories_table.php';
        self::assertFileExists($migration);
        $src = (string) file_get_contents($migration);
        self::assertStringContainsString('seo_content_project_topic_histories', $src);
        self::assertStringContainsString('planned_article_count', $src);
        self::assertStringContainsString('planner_run_id', $src);
        self::assertStringContainsString('NO legacy backfill', $src);
        self::assertStringNotContainsString('seo_project_tasks', $src);

        $writer = (string) file_get_contents(
            (string) (new ReflectionClass(TopicHistoryWriter::class))->getFileName(),
        );
        self::assertStringContainsString('firstOrCreate', $writer);
        self::assertStringContainsString('planner_run_id', $writer);
        self::assertStringContainsString('target_dna_count', $writer);
        self::assertStringNotContainsString('mcp', mb_strtolower($writer));

        $planner = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );
        self::assertStringContainsString('TopicHistoryWriter', $planner);
        self::assertStringContainsString('recordForPlannerRun', $planner);

        $model = (string) file_get_contents(
            (string) (new ReflectionClass(SeoContentProjectTopicHistory::class))->getFileName(),
        );
        self::assertStringContainsString('seo_content_project_topic_histories', $model);
    }

    public function test_month_detail_reads_topic_history_only(): void
    {
        $read = (string) file_get_contents(
            (string) (new ReflectionClass(SitePlanningReadModel::class))->getFileName(),
        );
        $detailStart = strpos($read, 'function cellDetail');
        self::assertNotFalse($detailStart);
        $detailBody = substr($read, $detailStart, 400);
        self::assertStringContainsString('topicHistory->forSiteMonth', $detailBody);
        self::assertStringNotContainsString('units->forSiteMonth', $detailBody);
        self::assertStringNotContainsString('unattributed', $detailBody);
        self::assertStringNotContainsString('MonthCoverage', $detailBody);

        $history = (string) file_get_contents(
            (string) (new ReflectionClass(TopicHistoryReadModel::class))->getFileName(),
        );
        self::assertStringContainsString('never reconstructs', $history);
        self::assertStringContainsString('SUM(planned_article_count)', $history);
        self::assertStringNotContainsString('seo_project_tasks', $history);
    }

    public function test_suggest_notes_exposes_planned_history_count(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(AuditNoteClusterSuggestionQuery::class))->getFileName(),
        );
        self::assertStringContainsString('planned_history_count', $src);
        self::assertStringContainsString('TopicHistoryReadModel', $src);
        self::assertStringNotContainsString('ContentProjectClusterCoverageIndex', $src);
        self::assertStringNotContainsString('project_coverage_count', $src);

        $blade = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');
        self::assertStringContainsString('planned_history_count', $blade);
        self::assertStringContainsString('audit_notes_planned_history', $blade);
        self::assertStringNotContainsString('project_coverage_count', $blade);
    }

    public function test_month_detail_ui_shows_topic_name_and_count_only(): void
    {
        $blade = LegacyAddonPath::read('resources/views/components/content-project-site-planning.blade.php');
        self::assertStringContainsString('detail.topics', $blade);
        self::assertStringContainsString('topic.topic_name', $blade);
        self::assertStringContainsString('planned_article_count', $blade);
        self::assertStringContainsString('data-site-planning-topic-history="1"', $blade);
        self::assertStringNotContainsString('detail.groups', $blade);
        self::assertStringNotContainsString('item.coverage_status', $blade);
        self::assertStringNotContainsString('data-site-planning-active-totals', $blade);
        self::assertStringNotContainsString('data-site-planning-coverage="1"', $blade);
        self::assertStringNotContainsString('Unattributed', $blade);
    }
}
