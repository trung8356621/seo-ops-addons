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

        self::assertStringContainsString("THEN 'archive'", $source);
        self::assertStringContainsString("THEN 'content_project'", $source);
        self::assertStringContainsString("THEN 'draft'", $source);
        self::assertStringContainsString("ELSE 'none'", $source);
        self::assertStringContainsString('COALESCE(list_spt.active_project_id, list_spt.draft_project_id)', $source);
        self::assertStringContainsString('WHEN list_cai.article_id IS NOT NULL THEN NULL', $source);
    }

    public function test_list_join_includes_content_project_state_subquery(): void
    {
        $resource = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php';
        $source = (string) file_get_contents($resource);

        self::assertStringContainsString('function contentProjectStateSubquery', $source);
        self::assertStringContainsString('leftJoinSub', $source);
        self::assertStringContainsString("'list_spt'", $source);
        self::assertStringContainsString('SeoProjectTask::query()', $source);
        self::assertStringContainsString('withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)', $source);
        self::assertStringContainsString("join('seo_projects as projected_projects'", $source);
        self::assertStringContainsString("whereNull('projected_tasks.deleted_at')", $source);
        self::assertStringContainsString("->groupBy('projected_tasks.article_id')", $source);
        self::assertStringContainsString("projected_tasks.status', '!=', SeoProjectTask::STATUS_CANCELLED", $source);
        self::assertStringContainsString("projected_projects.kind', '!=', SeoProject::KIND_ARCHIVE", $source);
        self::assertStringContainsString("orWhereNull('projected_projects.kind')", $source);
        self::assertStringContainsString('SeoProject::STATUS_DRAFT', $source);
        $projection = $this->sourceBetween($source, 'private static function contentProjectStateSubquery', 'public static function getPages');
        self::assertStringNotContainsString("->whereIn('type'", $projection);
    }

    public function test_article_assigned_content_project_id_uses_projected_fields(): void
    {
        $resource = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php';
        $source = (string) file_get_contents($resource);

        self::assertStringContainsString('if (array_key_exists(\'content_project_id\', $article->getAttributes()))', $source);
        self::assertStringContainsString('(int) $projectId', $source);
        self::assertStringContainsString('Fallback to per-row query for non-list contexts', $source);
    }

    public function test_initial_list_seo_cell_uses_projection_without_full_summary(): void
    {
        $view = ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/tables/columns/article-seo-details.blade.php';
        $source = (string) file_get_contents($view);

        self::assertStringContainsString("getAttribute('seo_score')", $source);
        self::assertStringContainsString("getAttribute('skip_seo_score')", $source);
        self::assertStringContainsString("firstWhere('meta_key', 'seo_focus_keyword')", $source);
        self::assertStringNotContainsString('ArticleListSeoSummary::for(', $source);
        self::assertStringNotContainsString('seoProfile', $source);
        self::assertStringNotContainsString('resolveFocusKeywordForArticle', $source);
    }

    public function test_seo_dropdown_has_retryable_lazy_state_and_named_route(): void
    {
        $view = ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/tables/columns/article-seo-details.blade.php';
        $source = (string) file_get_contents($view);

        self::assertStringContainsString("route('seo.articles.list-seo-details'", $source);
        self::assertStringContainsString('toggleSeoDetails()', $source);
        self::assertStringContainsString('seoDetailsLoading: false', $source);
        self::assertStringContainsString('seoDetailsError: false', $source);
        self::assertStringNotContainsString('$watch(\'open\'', $source);
        self::assertStringNotContainsString('this.seoDetails = { error: true }', $source);

        foreach (['vi', 'en'] as $locale) {
            $translations = require ProjectRoot::addonsPath()."/seo-content-ai-compat/lang/{$locale}/filament.php";
            self::assertNotSame('seo-content-ai::filament.article_list.loading', $translations['article_list']['loading']);
            self::assertArrayHasKey('load_seo_details', $translations['article_list']);
            self::assertArrayHasKey('seo_details_error', $translations['article_list']);
        }
    }

    public function test_article_list_project_url_does_not_query_project_per_row(): void
    {
        $resource = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php';
        $source = (string) file_get_contents($resource);

        self::assertStringContainsString("SeoProjectResource::getUrl('view', ['record' => \$projectId])", $source);
        $rowActions = $this->sourceBetween($source, "Action::make('view_content_project_runs')", 'AssignToContentProjectActionFactory::tableRowAction');
        self::assertStringNotContainsString('SeoProject::query()->find(', $rowActions);
    }

    public function test_lazy_seo_details_endpoint_exists(): void
    {
        $provider = ProjectRoot::addonsPath().'/seo-content-ai-compat/Providers/SeoPanelProvider.php';
        $source = (string) file_get_contents($provider);

        self::assertStringContainsString('Route::get(\'/articles/{article}/list-seo-details\'', $source);
        self::assertStringContainsString('ArticleListSeoDetailsController::class', $source);

        $controller = ProjectRoot::addonsPath().'/content/src/Http\Controllers/ArticleListSeoDetailsController.php';
        self::assertFileExists($controller);
        $controllerSource = (string) file_get_contents($controller);
        self::assertStringContainsString('__invoke', $controllerSource);
        self::assertStringContainsString('abort_unless($this->canViewArticle($article), 403)', $controllerSource);
        self::assertStringContainsString('ArticleListSeoSummary::for($article)', $controllerSource);
        self::assertStringContainsString('schema', $controllerSource);
        self::assertStringContainsString('image_count', $controllerSource);
        self::assertStringContainsString('faq_count', $controllerSource);
        self::assertStringContainsString('faq_points', $controllerSource);
        self::assertStringContainsString('featured_snippet_points', $controllerSource);
        self::assertStringContainsString('links_total', $controllerSource);
        self::assertStringContainsString('links_internal', $controllerSource);
        self::assertStringContainsString('links_external', $controllerSource);
        self::assertStringNotContainsString('->save(', $controllerSource);
        self::assertStringNotContainsString('->update(', $controllerSource);
    }

    private function sourceBetween(string $source, string $start, string $end): string
    {
        $startOffset = strpos($source, $start);
        self::assertNotFalse($startOffset);
        $endOffset = strpos($source, $end, $startOffset);
        self::assertNotFalse($endOffset);

        return substr($source, $startOffset, $endOffset - $startOffset);
    }
}
