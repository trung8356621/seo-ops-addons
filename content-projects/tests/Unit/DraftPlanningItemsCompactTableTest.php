<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftPlanningItemsReadModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Compact Draft items table: Post type, Added, SEO inline, WP-style row actions.
 */
final class DraftPlanningItemsCompactTableTest extends TestCase
{
    public function test_read_model_exposes_post_type_and_immutable_added_at(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectDraftPlanningItemsReadModel::class))->getFileName(),
        );

        self::assertStringContainsString("'post_type'", $src);
        self::assertStringContainsString("'post_type_label'", $src);
        self::assertStringContainsString("'added_at'", $src);
        self::assertStringContainsString("'added_label'", $src);
        self::assertStringContainsString('$origin->created_at', $src);
        self::assertStringContainsString('$task->created_at', $src);
        self::assertStringContainsString("'itemOrigin'", $src);
        self::assertStringNotContainsString('draft_added_at', $src);
    }

    public function test_post_type_labels_map_article_product_page(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectDraftPlanningItemsReadModel::class))->getFileName(),
        );

        self::assertStringContainsString('function postTypeLabel', $src);
        self::assertStringContainsString("SeoProjectTask::POST_TYPE_ARTICLE, 'post'", $src);
        self::assertStringContainsString('post_type_post', $src);
        self::assertStringContainsString('POST_TYPE_PRODUCT', $src);
        self::assertStringContainsString('post_type_product', $src);
        self::assertStringContainsString("'page' =>", $src);
        self::assertStringContainsString('post_type_page', $src);
        self::assertSame('article', SeoProjectTask::POST_TYPE_ARTICLE);
        self::assertSame('product', SeoProjectTask::POST_TYPE_PRODUCT);
    }

    public function test_ui_has_compact_columns_and_no_standalone_seo_check_actions(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString('planning_col_post_type', $items);
        self::assertStringContainsString('planning_col_added', $items);
        self::assertStringContainsString('cp-plan-seo-inline', $items);
        self::assertStringContainsString('cp-plan-row-actions--under', $items);
        self::assertStringContainsString('seoPrefix', $items);
        self::assertStringContainsString('labelRemove', $items);

        self::assertStringNotContainsString('suggestions_col_seo', $items);
        self::assertStringNotContainsString('suggestions_col_check_index', $items);
        self::assertStringNotContainsString('suggestions_col_actions', $items);
        self::assertStringNotContainsString('can_view_generation_run', $items);
    }

    public function test_ui_preserves_inline_edit_review_and_capability_gated_actions(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString('startEdit(row, \'title\')', $items);
        self::assertStringContainsString('startEdit(row, \'description\')', $items);
        self::assertStringContainsString('startEdit(row, \'keyword\')', $items);
        self::assertStringContainsString('toggleReview', $items);
        self::assertStringContainsString('row.can_edit_article', $items);
        self::assertStringContainsString('row.can_open_public', $items);
        self::assertStringContainsString('row.can_check_index', $items);
        self::assertStringContainsString('row.can_skip_seo_audit', $items);
        self::assertStringContainsString('archiveRow(row)', $items);
        self::assertStringContainsString('target="_blank"', $items);
        self::assertStringContainsString('cp-plan-draft--full', $items);
    }

    public function test_styles_hide_added_before_post_type_on_narrow(): void
    {
        $styles = LegacyAddonPath::read('resources/views/components/content-project-ops-styles.blade.php');

        self::assertStringContainsString('cp-plan-draft-table__col-added', $styles);
        self::assertStringContainsString('cp-plan-draft-table__col-post-type', $styles);
        self::assertStringContainsString('cp-plan-seo-inline', $styles);
        self::assertStringContainsString('cp-plan-row-actions--under', $styles);
        self::assertMatchesRegularExpression(
            '/max-width:\s*1100px[\s\S]*col-added[\s\S]*display:\s*none/',
            $styles,
        );
    }
}
