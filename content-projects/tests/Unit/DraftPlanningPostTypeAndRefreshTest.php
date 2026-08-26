<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\UpdateContentProjectItemHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftPlanningItemsReadModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Draft inline post_type correction + AI completion Draft refresh.
 */
final class DraftPlanningPostTypeAndRefreshTest extends TestCase
{
    public function test_normalize_editable_planner_post_type_accepts_aliases(): void
    {
        self::assertSame(
            SeoProjectTask::POST_TYPE_ARTICLE,
            UpdateContentProjectItemHandler::normalizeEditablePlannerPostType('article'),
        );
        self::assertSame(
            SeoProjectTask::POST_TYPE_ARTICLE,
            UpdateContentProjectItemHandler::normalizeEditablePlannerPostType('post'),
        );
        self::assertSame(
            SeoProjectTask::POST_TYPE_PRODUCT,
            UpdateContentProjectItemHandler::normalizeEditablePlannerPostType('product'),
        );
    }

    public function test_normalize_editable_planner_post_type_rejects_invalid(): void
    {
        foreach (['page', 'category', 'product_category', 'custom', 'bogus', ''] as $bad) {
            self::assertNull(
                UpdateContentProjectItemHandler::normalizeEditablePlannerPostType($bad),
                'Expected reject for: '.$bad,
            );
        }
    }

    public function test_handler_whitelists_post_type_with_create_only_guards(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(UpdateContentProjectItemHandler::class))->getFileName(),
        );

        self::assertStringContainsString("'post_type'", $src);
        self::assertStringContainsString('applyPlannerPostTypeUpdate', $src);
        self::assertStringContainsString('normalizeEditablePlannerPostType', $src);
        self::assertStringContainsString('TYPE_CREATE', $src);
        self::assertStringContainsString('article_id', $src);
        self::assertStringContainsString('planning_reviewed_at = null', $src);
        self::assertStringContainsString('Invalid post_type', $src);
        // Must NOT silently map via normalizePostType() for planner edits.
        self::assertStringNotContainsString(
            'SeoProjectTask::normalizePostType($rawValue)',
            $src,
        );
        // Preserve product fields — do not clear loai_san_pham / description.
        self::assertStringNotContainsString("loai_san_pham' => null", $src);
        self::assertStringNotContainsString("'description' => null", $src);
    }

    public function test_page_routes_post_type_through_command_bus(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringContainsString('function updateDraftPlanningItem', $src);
        self::assertStringContainsString('UpdateContentProjectItemCommand', $src);
        self::assertStringContainsString("'post_type'", $src);
        self::assertStringContainsString('draftPlanningRefreshNonce', $src);
        self::assertStringContainsString("#[On('cp-ops-refresh')]", $src);
        self::assertStringContainsString('function onCpOpsRefresh', $src);
        self::assertStringNotContainsString('window.location.reload', $src);
        self::assertStringNotContainsString('location.reload', $src);
    }

    public function test_read_model_exposes_can_edit_post_type(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectDraftPlanningItemsReadModel::class))->getFileName(),
        );

        self::assertStringContainsString("'can_edit_post_type'", $src);
        self::assertStringContainsString('TYPE_CREATE', $src);
        self::assertStringContainsString('STATUS_PENDING', $src);
    }

    public function test_draft_items_ui_has_inline_post_type_select_and_refresh_key(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');
        $page = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');

        self::assertStringContainsString('can_edit_post_type', $items);
        self::assertStringContainsString('startPostTypeEdit', $items);
        self::assertStringContainsString('editingPostTypeId', $items);
        self::assertStringContainsString('@dblclick.prevent="startPostTypeEdit(row)"', $items);
        self::assertStringContainsString('changePostType', $items);
        self::assertStringContainsString('updateDraftPlanningItem', $items);
        self::assertStringContainsString('cp-plan-inline-select', $items);
        self::assertStringContainsString('showProductDescription', $items);
        self::assertStringContainsString('product_description', $items);
        self::assertStringContainsString('productDescriptionLabel', $items);
        self::assertStringContainsString("'value' => 'article'", $items);
        self::assertStringContainsString('refreshNonce', $items);
        self::assertStringContainsString('wire:key="cp-draft-items-', $items);
        self::assertStringContainsString('draftPlanningRefreshNonce', $page);
        self::assertStringContainsString('supports-product', $page);
        // Default state is plain text — select only when editingPostTypeId matches.
        self::assertStringContainsString('editingPostTypeId === row.id', $items);
        self::assertStringContainsString('editingPostTypeId !== row.id', $items);
        self::assertStringNotContainsString('window.location.reload', $items);
        self::assertStringNotContainsString('wire:poll', $items);
    }

    public function test_read_model_exposes_product_description_separately_from_global(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectDraftPlanningItemsReadModel::class))->getFileName(),
        );

        self::assertStringContainsString("'product_description'", $src);
        self::assertStringContainsString('isProductPostType', $src);
        self::assertStringContainsString('secondary_description', $src);
        // Product gallery must not fall into global description when post_type=product.
        self::assertStringContainsString('! $isProductPostType', $src);
    }

    public function test_post_type_change_preserves_product_fields(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(UpdateContentProjectItemHandler::class))->getFileName(),
        );

        self::assertStringContainsString('Does not wipe product fields', $src);
        self::assertStringNotContainsString("loai_san_pham' => null", $src);
        self::assertStringNotContainsString("\$task->description = null", $src);
        self::assertStringNotContainsString("\$task->loai_san_pham = null", $src);
    }

    public function test_new_content_still_emits_cp_ops_refresh_on_completion(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource/Concerns/InteractsWithNewContentSuggestions.php',
        );

        self::assertStringContainsString('function refreshNewContentRun', $src);
        self::assertStringContainsString("dispatch('cp-ops-refresh')", $src);
        self::assertStringContainsString("'completed'", $src);
    }

    public function test_title_keyword_description_editing_path_unchanged(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringContainsString("in_array(\$field, ['title', 'keyword', 'description'], true)", $src);
        self::assertStringContainsString('applyPlanningKeyword', $src);
        self::assertStringContainsString('applyPlanningDescription', $src);
    }
}
