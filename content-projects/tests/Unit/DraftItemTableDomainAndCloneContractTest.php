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

    /**
     * SSR + Alpine hydration must keep identical column order/count.
     * When adding a column: update thead, SSR @foreach row, Alpine x-for row, and $rows boot mapping.
     */
    public function test_draft_table_hydration_column_parity_includes_domain(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString('planning_col_domain', $items);
        self::assertStringContainsString("'domain' => \$domain,", $items);
        self::assertStringContainsString("'site_id' => \$siteId > 0 ? \$siteId : null,", $items);
        self::assertStringContainsString("\$ssrRow['domain']", $items);
        self::assertStringContainsString('data-draft-ssr-row', $items);
        self::assertStringContainsString('domain: String(row.domain ?? cfg.domainBlank ??', $items);
        self::assertStringContainsString('x-text="row.domain || domainBlank"', $items);
        self::assertStringContainsString('cp-plan-draft-table__col-domain', $items);

        self::assertMatchesRegularExpression(
            '/suggestions_col_article[\s\S]*?planning_col_domain[\s\S]*?planning_col_keywords[\s\S]*?planning_col_post_type[\s\S]*?suggestions_col_plan[\s\S]*?planning_col_review[\s\S]*?planning_col_added[\s\S]*?planning_col_actions/',
            $items,
        );

        self::assertMatchesRegularExpression(
            '/cp-plan-article-cell[\s\S]*?cp-plan-draft-table__col-domain[\s\S]*?startEdit\(row, \'keyword\'\)/',
            $items,
        );

        $headerCount = self::countTableHeaderCells($items);
        $ssrCount = self::countSsrRowCells($items);
        $alpineCount = self::countAlpineRowCells($items);

        self::assertSame(9, $headerCount, 'Expected 9 thead columns (checkbox + 8 data columns)');
        self::assertSame($headerCount, $ssrCount, 'SSR row TD count must match THEAD TH count');
        self::assertSame($headerCount, $alpineCount, 'Alpine row TD count must match THEAD TH count');
    }

    /**
     * Draft rows are Alpine-owned between keyed Livewire remounts.
     * Livewire must not morph tbody children because doing so can resurrect SSR
     * fallback rows after Alpine init.
     */
    public function test_draft_table_alpine_dom_ownership_prevents_ssr_resurrection(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString('data-draft-ssr-row', $items);
        self::assertStringContainsString('<template x-for="row in rows"', $items);
        self::assertStringContainsString('wire:ignore', $items);
        self::assertStringContainsString('data-draft-tbody-alpine-owned', $items);
        self::assertStringContainsString('wire:key="cp-draft-items-{{ $reviewFilter }}-{{ $typeFilter }}-{{ $draftDomainFilter }}-{{ (int) $refreshNonce }}"', $items);
        self::assertStringContainsString('cpPlanDraftItems(@js($boot))', $items);
        self::assertStringContainsString("this.qsa('[data-draft-ssr-row]')", $items);

        self::assertStringNotContainsString('setTimeout', $items);
        if (preg_match('/init\(\)\s*\{([\s\S]*?)\n\s*\},/', $items, $initMatch)) {
            self::assertStringNotContainsString('setTab(', $initMatch[1]);
            self::assertStringNotContainsString('$wire.setDraftReviewFilter', $initMatch[1]);
        } else {
            self::fail('init() block not found in draft items component');
        }
    }

    private static function countTableHeaderCells(string $blade): int
    {
        if (! preg_match('/<thead[\s\S]*?<\/thead>/', $blade, $match)) {
            return 0;
        }

        preg_match_all('/<th[\s>]/', $match[0], $cells);

        return count($cells[0]);
    }

    private static function countSsrRowCells(string $blade): int
    {
        if (! preg_match('/<tr data-draft-ssr-row="[^"]*">([\s\S]*?)<\/tr>/', $blade, $match)) {
            return 0;
        }

        return substr_count($match[1], '<td');
    }

    private static function countAlpineRowCells(string $blade): int
    {
        if (! preg_match('/<template x-for="row in rows"[\s\S]*?<tr[\s\S]*?>([\s\S]*?)<\/tr>\s*<\/template>/', $blade, $match)) {
            return 0;
        }

        return substr_count($match[1], '<td');
    }
}
