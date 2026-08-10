<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for Article List hang fix:
 * list must not hydrate body/blocks, must not call WP via image count,
 * diagnostics default off, pagination stays DB-side.
 */
final class ArticleListPerformanceContractTest extends TestCase
{
    public function test_list_select_excludes_heavy_content_columns(): void
    {
        $resource = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php';
        $source = (string) file_get_contents($resource);

        self::assertStringContainsString('function listSelectColumns', $source);
        self::assertStringContainsString('function applyListSelectColumns', $source);
        self::assertStringContainsString("'articles.title'", $source);
        self::assertStringContainsString("'articles.internal_link_count'", $source);
        self::assertStringNotContainsString("'articles.body'", $source);
        self::assertStringNotContainsString("'articles.blocks'", $source);
        self::assertStringNotContainsString("'articles.editor_document'", $source);
        self::assertStringContainsString('applyListSelectColumns($query)', $this->listArticlesSource());
    }

    public function test_list_seo_summary_uses_cached_image_count_not_wp_resolve(): void
    {
        $summary = ProjectRoot::addonsPath().'/content/src/Support/ArticleListSeoSummary.php';
        $source = (string) file_get_contents($summary);

        self::assertStringContainsString('countCachedForArticle', $source);
        self::assertStringNotContainsString('countForArticle($article)', $source);
        self::assertStringNotContainsString('WordPressArticleContentService', $source);
        self::assertStringNotContainsString('resolveEditorHtml', $source);
        self::assertStringNotContainsString('Http::', $source);

        $images = ProjectRoot::addonsPath().'/media/src/Services/ArticlePostImagesService.php';
        $imagesSource = (string) file_get_contents($images);
        self::assertStringContainsString('function countCachedForArticle', $imagesSource);
        self::assertStringContainsString('Do not use on Article List GET', $imagesSource);
    }

    public function test_list_seo_summary_reads_link_counts_from_attributes_only(): void
    {
        $source = (string) file_get_contents(ProjectRoot::addonsPath().'/content/src/Support/ArticleListSeoSummary.php');

        self::assertStringContainsString("array_key_exists('internal_link_count', \$attrs)", $source);
        self::assertStringContainsString("array_key_exists('external_link_count', \$attrs)", $source);
        // Call-only: do not fail on comments mentioning the method name.
        self::assertDoesNotMatchRegularExpression(
            '/(?<![\w>])\$[a-zA-Z_][\w>]*->resolveExtractedLinks\s*\(/',
            $source,
        );
        self::assertDoesNotMatchRegularExpression(
            '/(?<![\w:])resolveExtractedLinks\s*\(\s*\)/',
            $source,
        );
    }

    public function test_article_eager_loads_include_faqs_and_sync_flag_meta(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php',
        );

        self::assertStringContainsString("'faqs'", $source);
        self::assertStringContainsString('META_WP_DATA_OUT_OF_SYNC', $source);
    }

    public function test_list_articles_does_not_call_wordpress_on_mount(): void
    {
        $source = $this->listArticlesSource();

        self::assertStringNotContainsString('WordPressArticleContentService', $source);
        self::assertStringNotContainsString('fetchFromWordPress', $source);
        self::assertStringNotContainsString('Http::', $source);
        // WP sync only on explicit Livewire actions, not GET mount/table.
        self::assertStringContainsString('function syncArticleMainKeyword', $source);
        self::assertStringContainsString('function resyncArticleSyncQueue', $source);
    }

    public function test_pagination_options_remain_database_bounded(): void
    {
        $resource = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php',
        );

        self::assertStringContainsString('->defaultPaginationPageOption(30)', $resource);
        self::assertStringContainsString('->paginationPageOptions([10, 30, 50, 100])', $resource);
        self::assertDoesNotMatchRegularExpression(
            "/paginationPageOptions\\(\\[[^\\]]*['\"]all['\"]/",
            $resource,
        );
    }

    public function test_sync_queue_badge_uses_short_cache(): void
    {
        $source = $this->listArticlesSource();

        self::assertStringContainsString('Cache::remember', $source);
        self::assertStringContainsString('sync_queue_badge_cache_seconds', $source);
    }

    public function test_diagnostics_default_off_and_safe_logging(): void
    {
        $config = (string) file_get_contents(LegacyAddonPath::resolve('config/article_list.php'));
        self::assertStringContainsString("env('SEO_ARTICLE_LIST_DIAGNOSTICS', false)", $config);

        $diag = (string) file_get_contents(ProjectRoot::addonsPath().'/content/src/Support/ArticleListDiagnostics.php');
        self::assertStringContainsString('Never logs body/content, tokens, passwords', $diag);
        self::assertStringContainsString('bindings_count', $diag);
        self::assertStringNotContainsString("'bindings' =>", $diag);
        self::assertStringNotContainsString('bindings_values', $diag);
        self::assertStringContainsString('count($event->bindings)', $diag);
        self::assertStringContainsString('RuntimeLogger::info', $diag);
        self::assertStringContainsString("'slow_queries'", $diag);
    }

    public function test_list_mount_reports_exceptions_with_request_id(): void
    {
        $source = $this->listArticlesSource();

        self::assertStringContainsString('ArticleListDiagnostics::begin', $source);
        self::assertStringContainsString('RuntimeLogger::report', $source);
        self::assertStringContainsString('request_id', $source);
        self::assertStringNotContainsString('location.reload', $source);
        self::assertStringNotContainsString('setInterval', $source);
    }

    public function test_provider_merges_article_list_config(): void
    {
        $provider = (string) file_get_contents(LegacyAddonPath::resolve('SeoContentAiServiceProvider.php'));
        self::assertStringContainsString(
            "mergeConfigFrom(__DIR__.'/config/article_list.php', 'seo-content-ai.article_list')",
            $provider,
        );
    }

    public function test_append_helpers_use_list_select_not_star(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php',
        );

        self::assertStringNotContainsString("\$query->select('articles.*');", $source);
        self::assertStringContainsString('static::applyListSelectColumns($query)', $source);
    }

    private function listArticlesSource(): string
    {
        return (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/ListArticles.php',
        );
    }
}
