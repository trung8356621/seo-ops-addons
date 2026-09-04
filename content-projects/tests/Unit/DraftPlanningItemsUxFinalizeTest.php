<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ArchiveProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftPlanningItemsReadModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Draft Items UX finalize: review, inline edit, global skip, no State column.
 */
final class DraftPlanningItemsUxFinalizeTest extends TestCase
{
    public function test_archive_command_distinguishes_global_skip_from_user_reject(): void
    {
        $reject = new ArchiveProjectItemsCommand(1, [10], removeReason: ArchiveProjectItemsCommand::REASON_USER_REJECT);
        $skip = new ArchiveProjectItemsCommand(1, [10], removeReason: ArchiveProjectItemsCommand::REASON_GLOBAL_SKIP);

        self::assertTrue($reject->shouldRecordSuggestionDismissal());
        self::assertFalse($skip->shouldRecordSuggestionDismissal());
        self::assertSame('global_skip', ArchiveProjectItemsCommand::REASON_GLOBAL_SKIP);
    }

    public function test_archive_handler_skips_dismissal_when_global_skip(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArchiveProjectItemsHandler::class))->getFileName(),
        );

        self::assertStringContainsString('shouldRecordSuggestionDismissal()', $src);
        self::assertStringContainsString('Global Skip archives without dismissal', $src);
        self::assertStringContainsString('dismissArticles', $src);
    }

    public function test_planner_global_skip_archives_without_dismissal_then_removes_draft_item(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringContainsString('SkipSeoAuditArticlesCommand', $page);
        self::assertStringContainsString('REASON_GLOBAL_SKIP', $page);
        self::assertStringContainsString('function skipSeoAuditOne', $page);
        self::assertStringContainsString('function updatePlanningField', $page);
        self::assertStringNotContainsString('openPlanningItemEdit', $page);
        self::assertStringNotContainsString('planningEditModal', $page);
    }

    public function test_draft_remove_uses_draft_toasts_not_archive_project_failed(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringContainsString('draft_remove_completed', $page);
        self::assertStringContainsString('draft_remove_failed', $page);
        self::assertStringContainsString('isDraftPlanning()', $page);
    }

    public function test_archive_tasks_allows_shared_draft_without_project_site_id(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\SeoProjectArchiveService::class))->getFileName(),
        );

        self::assertStringContainsString('Shared Draft has project.site_id = 0', $src);
        self::assertStringContainsString('$siteId = (int) ($task->site_id ?? 0)', $src);
        self::assertStringContainsString('task_site_missing', $src);
    }

    public function test_inline_update_uses_canonical_title_keyword_description(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringContainsString("'title', 'keyword', 'description'", $page);
        self::assertStringContainsString('applyPlanningKeyword', $page);
        self::assertStringContainsString('$task->title', $page);
        self::assertStringContainsString('$task->keyword', $page);
        self::assertStringContainsString('$task->description', $page);
    }

    public function test_read_model_exposes_keywords_issues_edit_article_without_state_label(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectDraftPlanningItemsReadModel::class))->getFileName(),
        );

        self::assertStringContainsString("'keyword'", $src);
        self::assertStringContainsString('issue_count', $src);
        self::assertStringContainsString('reason_codes', $src);
        self::assertStringContainsString('can_edit_article', $src);
        self::assertStringContainsString('ArticleResource::getUrl', $src);
        self::assertStringNotContainsString('state_label', $src);
        self::assertStringContainsString("->with([", $src);
        self::assertStringContainsString("'itemOrigin'", $src);
    }

    public function test_draft_items_ui_contract_inline_edit_full_width_no_state_no_modal(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');
        $page = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        $styles = LegacyAddonPath::read('resources/views/components/content-project-ops-styles.blade.php');

        self::assertStringContainsString('cp-plan-draft--full', $items);
        self::assertStringContainsString('planning_col_keywords', $items);
        self::assertStringContainsString('updatePlanningField', $items);
        self::assertStringContainsString('cpPlanDraftItems(@js($boot))', $items);
        self::assertStringContainsString('onEditBlur', $items);
        self::assertStringContainsString('blurGuardUntil', $items);
        self::assertStringContainsString('@dblclick', $items);
        self::assertStringContainsString('toggleReview', $items);
        self::assertStringContainsString('skipRow', $items);
        self::assertStringContainsString('bulkMarkReviewed', $items);
        self::assertStringContainsString('bulkArchive', $items);
        self::assertStringContainsString('data-draft-bulk-toolbar', $items);
        self::assertStringContainsString('item_action_edit_article', $items);
        self::assertStringContainsString('target="_blank"', $items);
        self::assertStringContainsString('planning_col_post_type', $items);
        self::assertStringContainsString('planning_col_added', $items);
        self::assertStringNotContainsString('suggestions_col_seo', $items);
        self::assertStringNotContainsString('suggestions_col_check_index', $items);
        self::assertStringNotContainsString('suggestions_col_actions', $items);
        self::assertStringNotContainsString('suggestions_col_issues', $items);
        self::assertStringNotContainsString('content_planning_col_source', $items);
        self::assertStringNotContainsString('suggestions_col_state', $items);
        self::assertStringNotContainsString('openPlanningItemEdit', $items);
        self::assertStringNotContainsString('data-planning-edit-modal', $page);
        self::assertStringNotContainsString('openPlanningItemEdit', $page);
        self::assertStringNotContainsString('data-inline-edit=\"', $items);
        self::assertStringContainsString('.cp-plan-draft--full', $styles);
        self::assertStringContainsString('max-width: none', $styles);
    }

    public function test_set_planning_reviewed_does_not_touch_article_review(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        $methodStart = strpos($page, 'function setPlanningReviewed');
        self::assertNotFalse($methodStart);
        $chunk = substr($page, $methodStart, 1600);

        self::assertStringContainsString('planning_reviewed_at', $chunk);
        self::assertStringContainsString('planning_reviewed_by', $chunk);
        self::assertStringNotContainsString('content_manager_reviewed', $chunk);
        self::assertStringNotContainsString('review_status', $chunk);
    }
}
