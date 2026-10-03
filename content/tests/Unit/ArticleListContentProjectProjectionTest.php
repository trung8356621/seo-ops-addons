<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Contract test for Content Project state projection in Article List.
 * Ensures projection eliminates per-row queries.
 */
final class ArticleListContentProjectProjectionTest extends TestCase
{
    public function test_list_select_includes_content_project_state_columns(): void
    {
        $resource = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php';
        $source = (string) file_get_contents($resource);

        self::assertStringContainsString("'list_spt.content_project_state as content_project_state'", $source);
        self::assertStringContainsString("'list_spt.content_project_id as content_project_id'", $source);
    }

    public function test_list_join_includes_content_project_state_subquery(): void
    {
        $resource = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php';
        $source = (string) file_get_contents($resource);

        self::assertStringContainsString('function contentProjectStateSubquery', $source);
        self::assertStringContainsString('leftJoinSub', $source);
        self::assertStringContainsString("'list_spt'", $source);
        self::assertStringContainsString('SeoProjectTask::class', $source);
        self::assertStringContainsString('SeoProject::class', $source);
        self::assertStringContainsString('active()', $source);
        self::assertStringContainsString('CASE', $source);
    }

    public function test_article_assigned_content_project_id_uses_projected_fields(): void
    {
        $resource = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php';
        $source = (string) file_get_contents($resource);

        self::assertStringContainsString('if (array_key_exists(\'content_project_id\', $article->getAttributes()))', $source);
        self::assertStringContainsString('return (int) $article->content_project_id', $source);
        self::assertStringContainsString('fallback query', $source);
    }

    public function test_list_seo_summary_uses_persisted_seo_score(): void
    {
        $summary = ProjectRoot::addonsPath().'/content/src/Support/ArticleListSeoSummary.php';
        $source = (string) file_get_contents($summary);

        self::assertStringContainsString('$article->seoProfile?->seo_score !== null', $source);
        self::assertStringContainsString('(int) round((float) $article->seoProfile->seo_score)', $source);
        self::assertStringNotContainsString('SeoRuleViolationsResolver::scoreForArticle', $source);
    }

    public function test_lazy_seo_details_endpoint_exists(): void
    {
        $provider = ProjectRoot::addonsPath().'/seo-content-ai-compat/src/Providers/SeoPanelProvider.php';
        $source = (string) file_get_contents($provider);

        self::assertStringContainsString('Route::get(\'/articles/{article}/list-seo-details\'', $source);
        self::assertStringContainsString('ArticleListSeoDetailsController::class', $source);

        $controller = ProjectRoot::addonsPath().'/content/src/Http\Controllers/ArticleListSeoDetailsController.php';
        self::assertFileExists($controller);
        $controllerSource = (string) file_get_contents($controller);
        self::assertStringContainsString('__invoke', $controllerSource);
        self::assertStringContainsString('schema', $controllerSource);
        self::assertStringContainsString('image_count', $controllerSource);
        self::assertStringContainsString('faq_count', $controllerSource);
        self::assertStringContainsString('faq_points', $controllerSource);
        self::assertStringContainsString('featured_snippet_points', $controllerSource);
        self::assertStringContainsString('links_total', $controllerSource);
        self::assertStringContainsString('links_internal', $controllerSource);
        self::assertStringContainsString('links_external', $controllerSource);
    }
}