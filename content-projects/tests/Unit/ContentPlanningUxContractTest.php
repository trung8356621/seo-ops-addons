<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectNewContentPlanner;
use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SplitDraftContentProjectCommand;
use Omnichannel\Addons\Publishing\Filament\Pages\PublishingQueueHub;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

/**
 * UI contract: unified Content Planning / Draft Planner presentation.
 */
final class ContentPlanningUxContractTest extends TestCase
{
    public function test_primary_planner_title_is_content_planning_not_seo_audit(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        $en = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/en/filament.php');
        $vi = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/vi/filament.php');

        self::assertStringContainsString('content_planning_title', $page);
        self::assertStringContainsString("'content_planning_title' => 'Content Planning'", $en);
        self::assertStringContainsString("'content_planning_title' => 'Lập kế hoạch nội dung'", $vi);
        self::assertStringNotContainsString("return __('seo-content-ai::filament.projects.seo_audit_title');", $page);
    }

    public function test_context_header_and_shared_draft_without_create_draft(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');

        self::assertStringContainsString('data-content-planning-context="1"', $blade);
        self::assertStringContainsString('content_planning_draft_label', $blade);
        self::assertStringContainsString('content_planning_working_site', $blade);
        self::assertStringContainsString('data-content-planning-action="publish"', $blade);
        self::assertStringContainsString('data-shared-planning-draft', $blade);
        self::assertStringContainsString('openPublishFromPlanner', $blade);
        self::assertStringContainsString('data-planning-draft-display', $blade);
        self::assertStringNotContainsString('data-content-planning-action="create-draft"', $blade);
        self::assertStringNotContainsString('content_planning_no_draft_yet', $blade);
        self::assertStringNotContainsString('seo_audit_create_draft', $blade);
        self::assertStringNotContainsString('<h1', $blade);
    }

    public function test_main_page_has_two_planner_cards_without_giant_candidate_table(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        $draft = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');

        self::assertStringContainsString('content-project-draft-planner', $blade);
        self::assertStringContainsString('content-project-draft-items', $blade);
        self::assertStringContainsString('data-planner-card="improve"', $draft);
        self::assertStringContainsString('content-project-new-content-card', $draft);
        self::assertStringContainsString('filtersOpen: true', $draft);
        self::assertStringContainsString('createTab', $draft);
        self::assertStringContainsString('data-create-tab="ideas"', $draft);
        self::assertStringContainsString('content-project-idea-candidate-picker', $draft);
        self::assertStringContainsString('cp-plan-tab-panels', $draft);
        self::assertStringContainsString('data-planner-filters="improve"', $draft);
        self::assertStringContainsString('seoAuditPlannerCardState', $draft);
        self::assertStringNotContainsString('planner_matched_count', $draft);
        self::assertStringNotContainsString('data-seo-audit-filter-row', $blade);
        self::assertStringNotContainsString('content-project-seo-audit-planner', $blade);
        self::assertStringNotContainsString('content_planning_open_advanced', $draft);
        self::assertStringNotContainsString('data-advanced-seo-audit', $draft);
        self::assertStringContainsString('cp-plan-filters-grid', $draft);
        self::assertStringContainsString('cp-plan-grid', $draft);
        self::assertStringContainsString('--cp-plan-panel-max', (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-ops-styles.blade.php'),
        ));
        self::assertStringContainsString('cp-plan-card__scroll', $draft);
        self::assertStringNotContainsString('historyOpen', $draft);
        self::assertStringNotContainsString('planner_history', $draft);
        self::assertStringNotContainsString('planner_load_filters', $draft);
        self::assertStringNotContainsString('planner_run_again', $draft);
    }

    public function test_legacy_advanced_param_redirects_to_canonical_planner(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');

        self::assertStringContainsString('shouldRedirectLegacyAdvancedParam', $page);
        self::assertStringContainsString('canonicalPlannerQueryParams', $page);
        self::assertStringNotContainsString("Url(as: 'advanced')", $page);
        self::assertStringNotContainsString('$this->advanced', $blade);
        self::assertStringNotContainsString('content-project-seo-audit-planner', $blade);
        self::assertStringNotContainsString('suggestions_add_to_draft', $blade);
        self::assertStringNotContainsString('content_planning_open_advanced', $blade);
        self::assertStringNotContainsString('planner_matched_count', $blade);
    }

    public function test_draft_items_table_uses_read_model_and_icon_actions(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString('ContentProjectDraftPlanningItemsReadModel', $page);
        self::assertStringContainsString('archiveOne', $page);
        self::assertStringContainsString('skipSeoAuditOne', $page);
        self::assertStringContainsString('setPlanningReviewed', $page);
        self::assertStringContainsString('cp-plan-seo-inline', $items);
        self::assertStringContainsString('planning_col_post_type', $items);
        self::assertStringContainsString('planning_col_added', $items);
        self::assertStringContainsString('suggestions_col_plan', $items);
        self::assertStringContainsString('planning_col_keywords', $items);
        self::assertStringContainsString('cp-plan-row-actions', $items);
        self::assertStringContainsString('planning_col_actions', $items);
        self::assertStringContainsString('planning_col_domain', $items);
        self::assertStringContainsString('cp-plan-article-icon', $items);
        self::assertStringContainsString('item_action_remove_from_draft', $items);
        self::assertStringContainsString('item_action_edit_article', $items);
        self::assertStringContainsString('updatePlanningField', $items);
        self::assertStringNotContainsString('suggestions_col_seo', $items);
        self::assertStringNotContainsString('suggestions_col_check_index', $items);
        self::assertStringNotContainsString('suggestions_col_actions', $items);
        self::assertStringNotContainsString('suggestions_col_issues', $items);
        self::assertStringNotContainsString('content_planning_col_source', $items);
        self::assertStringNotContainsString('suggestions_col_state', $items);
        self::assertStringNotContainsString('openPlanningItemEdit', $items);
        self::assertStringNotContainsString('Add to Draft', $items);
        self::assertStringNotContainsString('Dismiss from this Draft', $items);
    }

    public function test_publish_opens_split_draft_not_wordpress_publish(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        $trait = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Concerns/InteractsWithDraftSplit.php',
        );

        self::assertStringContainsString('InteractsWithDraftSplit', $page);
        self::assertStringContainsString('openDraftSplitModal', $page);
        self::assertStringContainsString('SplitDraftContentProjectCommand', $trait);
        self::assertSame('content_project.split_draft', (new SplitDraftContentProjectCommand(1))->name());
        self::assertStringNotContainsString('WordPressPublish', $page);
        self::assertStringNotContainsString('publishToWordPress', $page);
    }

    public function test_nav_nests_project_planner_and_archived_projects_under_projects(): void
    {
        $resource = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );
        $planner = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        $queue = (string) file_get_contents(
            (string) (new ReflectionClass(PublishingQueueHub::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectSeoAuditPlanner::getUrl()', $resource);
        self::assertStringContainsString('nav.archived_projects', $resource);
        self::assertStringContainsString("getUrl('archive')", $resource);
        self::assertStringContainsString('parentItem($parentLabel)', $resource);
        self::assertStringContainsString('shouldRegisterNavigation = false', $planner);
        self::assertStringContainsString('shouldRegisterNavigation = false', $queue);
        self::assertStringContainsString('SeoUserNavigation::moduleProjects()', $planner);
        self::assertStringContainsString('SeoProjectResource::getNavigationLabel()', $queue);
        self::assertStringNotContainsString('PublishingQueueHub::getNavigationLabel()', $resource);
    }

    public function test_legacy_new_content_redirects_to_content_planning(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectNewContentPlanner::class))->getFileName(),
        );

        self::assertStringContainsString("slug = 'content-projects/new-content'", $page);
        self::assertStringContainsString('ContentProjectSeoAuditPlanner::getUrl', $page);
        self::assertStringContainsString('redirect', $page);
        self::assertStringContainsString('shouldRegisterNavigation = false', $page);
    }

    public function test_lang_keys_exist_for_content_planning(): void
    {
        $en = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/en/filament.php');
        $vi = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/vi/filament.php');

        foreach ([
            'content_planning_nav_label',
            'content_planning_title',
            'content_planning_subtitle',
            'content_planning_publish',
            'content_planning_open_advanced',
            'planner_primary_language_missing_compact',
        ] as $key) {
            self::assertStringContainsString("'".$key."'", $en);
            self::assertStringContainsString("'".$key."'", $vi);
        }
    }
}
