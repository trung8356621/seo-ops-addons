<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Filament\Pages\ArticlesOptimal;
use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\Publishing\Filament\Pages\PublishingQueueHub;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

/**
 * Contract: SEO Audit UX cutover into Content Projects (nav + presentation).
 */
final class ContentProjectSeoAuditUxCutoverTest extends TestCase
{
    public function test_articles_optimal_hides_navigation_registration(): void
    {
        $page = $this->source(ArticlesOptimal::class);

        self::assertStringContainsString('shouldRegisterNavigation = false', $page);
        self::assertTrue(
            (new ReflectionClass(ArticlesOptimal::class))->getDefaultProperties()['shouldRegisterNavigation'] === false
            || str_contains($page, 'protected static bool $shouldRegisterNavigation = false')
        );
    }

    public function test_seo_project_resource_nav_nests_planner_and_queue_under_projects(): void
    {
        $resource = $this->source(SeoProjectResource::class);
        $planner = $this->source(ContentProjectSeoAuditPlanner::class);
        $queue = $this->source(PublishingQueueHub::class);

        self::assertStringContainsString('ContentProjectSeoAuditPlanner::getNavigationLabel()', $resource);
        self::assertStringContainsString('ContentProjectSeoAuditPlanner::getUrl()', $resource);
        self::assertStringContainsString('PublishingQueueHub::getUrl()', $resource);
        self::assertStringContainsString('parentItem($parentLabel)', $resource);
        self::assertStringContainsString('shouldRegisterNavigation = false', $planner);
        self::assertStringContainsString('shouldRegisterNavigation = false', $queue);
        self::assertStringContainsString('SeoUserNavigation::moduleProjects()', $planner);
        self::assertStringContainsString('SeoProjectResource::getNavigationLabel()', $queue);
    }

    public function test_new_page_slug_and_nav_nested_under_projects(): void
    {
        $page = $this->source(ContentProjectSeoAuditPlanner::class);

        self::assertStringContainsString("slug = 'content-projects/seo-audit'", $page);
        self::assertStringContainsString('shouldRegisterNavigation = false', $page);
        self::assertStringContainsString('InteractsWithSeoAuditSuggestions', $page);
        self::assertStringContainsString('WithPagination', $page);
        self::assertStringContainsString("view = 'seo-content-ai::filament.pages.content-project-seo-audit-planner'", $page);
        self::assertStringContainsString('canManageContentProjectWorkflow', $page);
    }

    public function test_articles_optimal_mount_redirects_to_planner(): void
    {
        $page = $this->source(ArticlesOptimal::class);

        self::assertStringContainsString('ContentProjectSeoAuditPlanner::getUrl', $page);
        self::assertStringContainsString('function mount(): void', $page);
        self::assertStringContainsString("\$params['site']", $page);
        self::assertStringContainsString('navigate: false', $page);
    }

    public function test_shared_planner_component_used_by_global_page_and_view_project(): void
    {
        $global = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/content-project-seo-audit-planner.blade.php'),
        );
        $viewOps = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php'),
        );
        $shared = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-seo-audit-planner.blade.php'),
        );
        $draft = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-draft-planner.blade.php'),
        );

        self::assertStringContainsString('content-project-draft-planner', $global);
        self::assertStringNotContainsString('content-project-seo-audit-planner', $global);
        self::assertStringNotContainsString('advanced', $global);
        self::assertStringContainsString('content-project-draft-planner', $viewOps);
        self::assertStringContainsString('suggestions_add_to_draft', $shared);
        self::assertStringContainsString('suggestionStateFilter', $shared);
        self::assertStringContainsString('setSuggestionScorePreset', $shared);
        self::assertStringContainsString('planner_fill_from_seo_audit', $draft);
        self::assertStringNotContainsString('selectedScoringRuleKeys', $shared);
        self::assertStringNotContainsString('wire:model="selectedScoringRuleKeys"', $global);
    }

    public function test_global_page_blade_has_no_scoring_rule_checkbox_wall(): void
    {
        $global = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/content-project-seo-audit-planner.blade.php'),
        );
        $shared = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-seo-audit-planner.blade.php'),
        );

        self::assertStringNotContainsString('selectedScoringRuleKeys', $global.$shared);
        self::assertStringNotContainsString('getScoringRuleFilterDefinitions', $global.$shared);
    }

    public function test_shared_planner_x_select_does_not_use_blade_disabled_directive(): void
    {
        $shared = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-seo-audit-planner.blade.php'),
        );

        self::assertDoesNotMatchRegularExpression(
            '/<x-select[^>]*@disabled/',
            $shared,
        );
        self::assertStringContainsString(':disabled="! $canWrite"', $shared);
    }

    public function test_publishing_queue_hub_nests_under_projects_module(): void
    {
        $hub = $this->source(PublishingQueueHub::class);
        self::assertStringContainsString('shouldRegisterNavigation = false', $hub);
        self::assertStringContainsString('SeoProjectResource::getNavigationLabel()', $hub);
        self::assertStringContainsString("slug = 'publishing-queue'", $hub);
    }

    public function test_list_articles_primary_audit_link_points_to_planner(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/list-articles.blade.php'),
        );

        self::assertStringContainsString('ContentProjectSeoAuditPlanner::getUrl()', $blade);
        self::assertStringNotContainsString('ArticlesOptimal::getUrl()', $blade);
    }

    public function test_lang_keys_exist_for_seo_audit_nav_and_help(): void
    {
        $en = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/en/filament.php');
        $vi = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/vi/filament.php');

        foreach (['seo_audit_nav_label', 'seo_audit_title', 'seo_audit_help', 'suggestions_add_to_draft', 'seo_audit_draft_empty', 'content_planning_title'] as $key) {
            self::assertStringContainsString("'".$key."'", $en);
            self::assertStringContainsString("'".$key."'", $vi);
        }
    }

    /**
     * @param  class-string  $class
     */
    private function source(string $class): string
    {
        return (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
    }
}
