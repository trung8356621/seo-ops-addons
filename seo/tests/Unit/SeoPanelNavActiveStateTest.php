<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\SeoPanelRoutes;
use PHPUnit\Framework\TestCase;

/**
 * Sidebar active-state mapping (route-name based; query strings ignored except articles tab discriminator).
 */
final class SeoPanelNavActiveStateTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    public static function acceptanceMatrix(): array
    {
        return [
            // Project list
            ['filament.seo-main.resources.content-projects.index', 'projectsList', true],
            ['filament.seo-main.resources.content-projects.index', 'projectPlanner', false],
            ['filament.seo-main.resources.content-projects.index', 'publishingQueue', false],
            ['filament.seo-main.resources.content-projects.index', 'projectsModule', true],

            // Project create
            ['filament.seo.resources.content-projects.create', 'projectsCreate', true],
            ['filament.seo.resources.content-projects.create', 'projectsList', false],
            ['filament.seo.resources.content-projects.create', 'projectsModule', true],

            // Project Planner (actual Filament name uses dots, not hyphen)
            ['filament.seo-main.pages.content-projects.seo-audit', 'projectPlanner', true],
            ['filament.seo-main.pages.content-projects.seo-audit', 'projectsList', false],
            ['filament.seo-main.pages.content-projects.seo-audit', 'publishingQueue', false],
            ['filament.seo-main.pages.content-projects.seo-audit', 'projectsModule', true],
            ['filament.seo-main.pages.content-projects.planner-runs', 'projectPlanner', true],

            // Wrong legacy hyphen name must NOT match planner
            ['filament.seo-main.pages.content-projects-seo-audit', 'projectPlanner', false],

            // Publishing Queue
            ['filament.seo-main.pages.publishing-queue', 'publishingQueue', true],
            ['filament.seo-main.pages.publishing-queue', 'projectsList', false],
            ['filament.seo-main.pages.publishing-queue', 'projectsModule', true],
            ['filament.seo.resources.content-projects.publishing-queue', 'publishingQueue', true],
            ['filament.seo.resources.content-projects.publishing-queue', 'projectsList', false],

            // Project detail
            ['filament.seo-main.resources.content-projects.view', 'projectsList', true],
            ['filament.seo-main.resources.content-projects.edit', 'projectsList', true],
            ['filament.seo-main.resources.content-projects.view', 'projectPlanner', false],

            // Archived Projects vault
            ['filament.seo.resources.content-projects.archive', 'projectsArchive', true],
            ['filament.seo.resources.content-projects.archive', 'projectsList', false],
            ['filament.seo.resources.content-projects.archive-preview', 'projectsArchive', true],
            ['filament.seo.resources.content-projects.archive-preview', 'projectsList', false],
            ['filament.seo.resources.content-projects.archive', 'projectsModule', true],

            // Articles list / editor
            ['filament.seo-main.resources.articles.index', 'articlesList', true],
            ['filament.seo-main.resources.articles.edit', 'articlesList', true],
            ['filament.seo-main.resources.articles.edit', 'articlesCategories', false],
            ['filament.seo-main.resources.articles.edit', 'articlesModule', true],

            // Keywords
            ['filament.seo-main.resources.keywords.index', 'keywordsDictionary', true],
            ['filament.seo-main.resources.keywords.index', 'keywordsFocus', false],
            ['filament.seo-main.resources.keywords.focus', 'keywordsFocus', true],
            ['filament.seo-main.resources.keywords.focus', 'keywordsDictionary', false],
            ['filament.seo-main.resources.keywords.clusters', 'keywordsClusters', true],
            ['filament.seo-main.resources.keywords.cluster', 'keywordsClusters', true],
            ['filament.seo-main.resources.keywords.cannibalization', 'keywordsCannibalization', true],
            ['filament.seo-main.resources.keywords.anchor-audit', 'keywordsBrokenLinks', true],
            ['filament.seo-main.resources.keywords.anchor-audit', 'keywordsDictionary', false],
            ['filament.seo-main.resources.keywords.focus', 'keywordsModule', true],
            ['filament.seo-main.pages.keywords.ai-discovery', 'keywordsModule', true],
            ['filament.seo-main.pages.keywords.ai-discovery', 'keywordsDictionary', false],

            // SEO
            ['filament.seo-main.pages.performance-hub', 'seoPerformance', true],
            ['filament.seo-main.pages.performance-hub', 'mcpIntelligence', false],
            ['filament.seo-main.pages.mcp-intelligence', 'mcpIntelligence', true],
            ['filament.seo-main.pages.mcp-intelligence', 'seoPerformance', false],
            ['filament.seo-main.pages.mcp-intelligence', 'seoModule', true],

            // Hệ thống
            ['filament.seo-main.pages.content-operations', 'operationCenter', true],
            ['filament.seo-main.pages.content-operations', 'pgCanary', false],
            ['filament.seo-main.pages.product-gallery-canary', 'pgCanary', true],
            ['filament.seo-main.pages.product-gallery-canary', 'systemModule', true],
        ];
    }

    /**
     * @dataProvider acceptanceMatrix
     */
    public function test_route_maps_to_exact_nav_flags(string $route, string $flag, bool $expected): void
    {
        self::assertSame($expected, $this->evaluate($route, $flag), "{$route} → {$flag}");
    }

    public function test_articles_categories_uses_tab_discriminator_only_on_index(): void
    {
        // Without explicit tab, index defaults to list (posts).
        self::assertTrue(SeoPanelRoutes::isArticlesListNav('filament.seo-main.resources.articles.index'));
        self::assertFalse(SeoPanelRoutes::isArticlesCategoriesNav('filament.seo-main.resources.articles.index'));

        self::assertTrue(SeoPanelRoutes::isArticlesCategoriesNav(
            'filament.seo-main.resources.articles.index',
            'categories',
        ));
        self::assertFalse(SeoPanelRoutes::isArticlesListNav(
            'filament.seo-main.resources.articles.index',
            'categories',
        ));

        // Edit must never become categories just because of shared prefix.
        self::assertFalse(SeoPanelRoutes::isArticlesCategoriesNav(
            'filament.seo-main.resources.articles.edit',
            'categories',
        ));
    }

    public function test_planner_query_string_route_still_matches_planner_only(): void
    {
        $route = 'filament.seo-main.pages.content-projects.seo-audit';

        self::assertTrue(SeoPanelRoutes::isProjectPlannerNav($route));
        self::assertFalse(SeoPanelRoutes::isProjectsListNav($route));
        self::assertFalse(SeoPanelRoutes::isProjectsCreateNav($route));
        self::assertFalse(SeoPanelRoutes::isPublishingQueueNav($route));
        self::assertTrue(SeoPanelRoutes::isProjectsModule($route));
    }

    public function test_expand_aliases_seo_and_seo_main(): void
    {
        $expanded = SeoPanelRoutes::expand(['filament.seo.pages.publishing-queue']);

        self::assertContains('filament.seo.pages.publishing-queue', $expanded);
        self::assertContains('filament.seo-main.pages.publishing-queue', $expanded);
    }

    public function test_only_one_project_child_flag_true_for_planner(): void
    {
        $route = 'filament.seo-main.pages.content-projects.seo-audit';
        $flags = [
            SeoPanelRoutes::isProjectsListNav($route),
            SeoPanelRoutes::isProjectsCreateNav($route),
            SeoPanelRoutes::isProjectPlannerNav($route),
            SeoPanelRoutes::isPublishingQueueNav($route),
            SeoPanelRoutes::isProjectsArchiveNav($route),
        ];

        self::assertSame(1, count(array_filter($flags)));
    }

    public function test_only_one_project_child_flag_true_for_archive(): void
    {
        $route = 'filament.seo.resources.content-projects.archive';
        $flags = [
            SeoPanelRoutes::isProjectsListNav($route),
            SeoPanelRoutes::isProjectsCreateNav($route),
            SeoPanelRoutes::isProjectPlannerNav($route),
            SeoPanelRoutes::isPublishingQueueNav($route),
            SeoPanelRoutes::isProjectsArchiveNav($route),
        ];

        self::assertSame(1, count(array_filter($flags)));
    }

    public function test_only_one_keyword_child_flag_true_for_focus(): void
    {
        $route = 'filament.seo.resources.keywords.focus';
        $flags = [
            SeoPanelRoutes::isKeywordsDictionaryNav($route),
            SeoPanelRoutes::isKeywordsFocusNav($route),
            SeoPanelRoutes::isKeywordsClustersNav($route),
            SeoPanelRoutes::isKeywordsCannibalizationNav($route),
            SeoPanelRoutes::isKeywordsBrokenLinksNav($route),
        ];

        self::assertSame(1, count(array_filter($flags)));
    }

    private function evaluate(string $route, string $flag): bool
    {
        return match ($flag) {
            'projectsModule' => SeoPanelRoutes::isProjectsModule($route),
            'projectsList' => SeoPanelRoutes::isProjectsListNav($route),
            'projectsCreate' => SeoPanelRoutes::isProjectsCreateNav($route),
            'projectPlanner' => SeoPanelRoutes::isProjectPlannerNav($route),
            'publishingQueue' => SeoPanelRoutes::isPublishingQueueNav($route),
            'projectsArchive' => SeoPanelRoutes::isProjectsArchiveNav($route),
            'articlesModule' => SeoPanelRoutes::isArticlesModule($route),
            'articlesList' => SeoPanelRoutes::isArticlesListNav($route),
            'articlesCategories' => SeoPanelRoutes::isArticlesCategoriesNav($route),
            'keywordsModule' => SeoPanelRoutes::isKeywordsModule($route),
            'keywordsDictionary' => SeoPanelRoutes::isKeywordsDictionaryNav($route),
            'keywordsFocus' => SeoPanelRoutes::isKeywordsFocusNav($route),
            'keywordsClusters' => SeoPanelRoutes::isKeywordsClustersNav($route),
            'keywordsCannibalization' => SeoPanelRoutes::isKeywordsCannibalizationNav($route),
            'keywordsBrokenLinks' => SeoPanelRoutes::isKeywordsBrokenLinksNav($route),
            'seoModule' => SeoPanelRoutes::isSeoModule($route),
            'seoPerformance' => SeoPanelRoutes::isSeoPerformanceNav($route),
            'mcpIntelligence' => SeoPanelRoutes::isMcpIntelligenceNav($route),
            'systemModule' => SeoPanelRoutes::isSystemModule($route),
            'operationCenter' => SeoPanelRoutes::isOperationCenterNav($route),
            'pgCanary' => SeoPanelRoutes::isPgCanaryNav($route),
            default => throw new \InvalidArgumentException('Unknown flag: '.$flag),
        };
    }
}
