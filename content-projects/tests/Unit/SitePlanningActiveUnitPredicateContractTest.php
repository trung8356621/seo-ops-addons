<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningActiveUnitAggregator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningActiveUnitPredicate;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningReadModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Contract: ACTIVE Site Planning units exclude terminal lifecycle; source ≠ attribution.
 */
final class SitePlanningActiveUnitPredicateContractTest extends TestCase
{
    public function test_predicate_excludes_completed_published_archived_project_completed(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SitePlanningActiveUnitPredicate::class))->getFileName(),
        );

        self::assertStringContainsString('STATUS_COMPLETED', $src);
        self::assertStringContainsString('STATUS_ARCHIVED', $src);
        self::assertStringContainsString('STATUS_CANCELLED', $src);
        self::assertStringContainsString('publish_published_at', $src);
        self::assertStringContainsString('SeoProject::STATUS_COMPLETED', $src);
    }

    public function test_aggregator_and_matrix_share_predicate(): void
    {
        $agg = (string) file_get_contents(
            (string) (new ReflectionClass(SitePlanningActiveUnitAggregator::class))->getFileName(),
        );

        self::assertSame(2, substr_count($agg, 'SitePlanningActiveUnitPredicate::constrainQuery'));
        self::assertSame(2, substr_count($agg, 'SitePlanningActiveUnitPredicate::acceptsRow'));
        self::assertStringContainsString('source_counts', $agg);
        self::assertStringContainsString('attributed', $agg);
        self::assertStringContainsString('SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST', $agg);
        self::assertStringContainsString('SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT', $agg);
        self::assertStringContainsString('SOURCE_UNKNOWN', $agg);
    }

    public function test_read_model_exposes_source_counts_separately_from_unattributed(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SitePlanningReadModel::class))->getFileName(),
        );
        self::assertStringContainsString("'source_counts'", $src);
        self::assertStringContainsString("'attributed'", $src);
        self::assertStringContainsString("'unattributed'", $src);
    }

    public function test_month_detail_blade_shows_source_and_attribution_separately(): void
    {
        $blade = LegacyAddonPath::read('resources/views/components/content-project-site-planning.blade.php');
        self::assertStringContainsString('data-site-planning-source-counts="1"', $blade);
        self::assertStringContainsString('data-site-planning-attr-counts="1"', $blade);
        self::assertStringContainsString('detail.source_counts', $blade);
        self::assertStringContainsString('detail.attributed?.count', $blade);
        self::assertStringContainsString('detail.unattributed?.count', $blade);
    }

    public function test_accepts_row_mirrors_terminal_rules(): void
    {
        $active = (object) [
            'status' => SeoProjectTask::STATUS_PENDING,
            'archived_at' => null,
            'deleted_at' => null,
            'publish_published_at' => null,
            'project_status' => SeoProject::STATUS_PENDING,
            'project_archived_at' => null,
        ];
        self::assertTrue(SitePlanningActiveUnitPredicate::acceptsRow($active));

        self::assertFalse(SitePlanningActiveUnitPredicate::acceptsRow((object) array_merge(
            (array) $active,
            ['status' => SeoProjectTask::STATUS_COMPLETED],
        )));
        self::assertFalse(SitePlanningActiveUnitPredicate::acceptsRow((object) array_merge(
            (array) $active,
            ['publish_published_at' => '2026-08-01 00:00:00'],
        )));
        self::assertFalse(SitePlanningActiveUnitPredicate::acceptsRow((object) array_merge(
            (array) $active,
            ['project_status' => SeoProject::STATUS_COMPLETED],
        )));
    }
}
