<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftPlanningItemsReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\CloneDraftCreateIdeaService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\DraftItemDomainRepairService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Shared Draft item table: Domain column, Clone idea, review guard, JS root safety.
 */
final class DraftItemTableDomainAndCloneContractTest extends TestCase
{
    public function test_create_and_rewrite_share_same_table_columns(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString('planning_col_domain', $items);
        self::assertStringContainsString('planning_col_keywords', $items);
        self::assertStringContainsString('planning_col_post_type', $items);
        self::assertStringContainsString('planning_col_review', $items);
        self::assertStringContainsString('planning_col_added', $items);
        self::assertStringContainsString('planning_col_actions', $items);
        self::assertStringContainsString('cp-plan-draft-table__col-domain', $items);
        self::assertStringContainsString('cp-plan-draft-table__col-actions', $items);
        self::assertStringContainsString('data-draft-plan', $items);
        self::assertStringNotContainsString('cp-plan-row-actions--under', $items);
    }

    public function test_js_root_resolves_safely_without_bare_dollar_root_query_selector(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString('rootEl()', $items);
        self::assertStringContainsString('qs(selector)', $items);
        self::assertStringContainsString("closest('[data-content-planning-draft-items]')", $items);
        self::assertStringNotContainsString('this.$root.querySelector(', $items);
        self::assertStringNotContainsString('this.$root.querySelectorAll(', $items);
        self::assertStringContainsString('startDomainEdit', $items);
        self::assertStringContainsString('data-domain-edit', $items);
        self::assertStringContainsString('@dblclick.prevent="startDomainEdit(row)"', $items);
    }

    public function test_clone_idea_service_resets_domain_title_article_keeps_keyword(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(CloneDraftCreateIdeaService::class))->getFileName(),
        );

        self::assertStringContainsString("'site_id' => null", $src);
        self::assertStringContainsString("'title' => null", $src);
        self::assertStringContainsString("'article_id' => null", $src);
        self::assertStringContainsString("'planning_reviewed_at' => null", $src);
        self::assertStringContainsString("'keyword' => \$source->keyword", $src);
        self::assertStringContainsString('TYPE_CREATE', $src);
        self::assertStringContainsString('Clone idea is only available for Create plan items', $src);
        self::assertStringContainsString('cloned_from_task_id', $src);
    }

    public function test_page_clone_and_review_domain_guard(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringContainsString('function cloneDraftIdea', $page);
        self::assertStringContainsString('CloneDraftCreateIdeaService', $page);
        self::assertStringContainsString('planning_domain_required_before_review', $page);
        self::assertStringContainsString('(int) ($task->site_id ?? 0) <= 0', $page);
        self::assertStringContainsString("'site_id'", $page);
        self::assertStringContainsString('DraftItemDomainRepairService', $page);
    }

    public function test_split_create_project_rejects_reviewed_without_site(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SplitDraftContentProjectService::class))->getFileName(),
        );

        self::assertStringContainsString('assertTaskReviewed', $src);
        self::assertStringContainsString('(int) ($task->site_id ?? 0) <= 0', $src);
        self::assertStringContainsString('missing Domain (site_id)', $src);
    }

    public function test_domain_repair_never_infers_from_keyword(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(DraftItemDomainRepairService::class))->getFileName(),
        );

        self::assertStringContainsString('function recoverSiteId', $src);
        self::assertStringContainsString('source_article_id', $src);
        self::assertStringContainsString('legacyProjectSite', $src);
        self::assertStringNotContainsString('$task->keyword', $src);
        self::assertStringNotContainsString("\$task->keyword", $src);
    }

    public function test_read_model_blank_create_title_and_clone_flag(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectDraftPlanningItemsReadModel::class))->getFileName(),
        );

        self::assertStringContainsString('isNewArticleType($type)', $src);
        self::assertStringContainsString("'can_clone_idea' => \$type === SeoProjectTask::TYPE_CREATE", $src);
        self::assertStringContainsString('DraftItemDomainRepairService', $src);
        self::assertStringContainsString("'domain'", $src);
        self::assertStringContainsString("'site_id'", $src);
    }

    public function test_ui_clone_filter_and_client_review_guard(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString('cloneIdea(row)', $items);
        self::assertStringContainsString('can_clone_idea', $items);
        self::assertStringContainsString('labelCloneIdea', $items);
        self::assertStringContainsString('data-draft-domain-filter', $items);
        self::assertStringContainsString('setDomainFilter', $items);
        self::assertStringContainsString('domainRequired', $items);
        self::assertStringContainsString('Domain is required before review', $items);
        self::assertStringContainsString('cp-plan-draft--full', $items);
    }
}
