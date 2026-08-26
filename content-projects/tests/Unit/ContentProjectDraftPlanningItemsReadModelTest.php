<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftPlanningItemsReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditCheckIndexUrl;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectDraftPlanningItemsReadModelTest extends TestCase
{
    public function test_read_model_eager_loads_relations_in_single_query_batch(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectDraftPlanningItemsReadModel::class))->getFileName(),
        );

        self::assertStringContainsString("'itemOrigin'", $src);
        self::assertStringContainsString("'article.seoProfile'", $src);
        self::assertStringContainsString("'article.articleMetas'", $src);
        self::assertStringContainsString('inContentProjectWorkingSet()', $src);
        self::assertStringContainsString('SeoAuditCheckIndexUrl::forCanonicalUrl', $src);
    }

    public function test_row_contract_includes_plan_source_and_action_flags(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectDraftPlanningItemsReadModel::class))->getFileName(),
        );

        foreach ([
            "'plan_label'",
            "'source_label'",
            "'seo_score_label'",
            "'issue_count_label'",
            "'can_skip_seo_audit'",
            "'planner_run_id'",
            "'can_check_index'",
            "'planning_reviewed'",
            "'description'",
            "'icon_kind'",
            "'post_type'",
            "'post_type_label'",
            "'added_at'",
            "'added_label'",
            'SeoContentProjectItemOrigin::SOURCE_SEO_AUDIT',
            'SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT',
            "'Create'",
            "'rows'",
            "'counts'",
        ] as $needle) {
            self::assertStringContainsString($needle, $src);
        }

        self::assertStringNotContainsString("'can_view_generation_run'", $src);
        self::assertStringNotContainsString('ContentProjectPlannerRunDetail', $src);
    }

    public function test_check_index_url_builder_is_canonical(): void
    {
        self::assertStringContainsString(
            'ArticleIndexCheckUrlBuilder',
            (string) file_get_contents(
                (string) (new ReflectionClass(SeoAuditCheckIndexUrl::class))->getFileName(),
            ),
        );
    }
}
