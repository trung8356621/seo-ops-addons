<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Regression: Article List thumb must follow Laravel featured canonical (DB-only),
 * not WordPress HTTP, and must keep featured meta keys under list eager-load.
 */
final class ArticleListFeaturedThumbnailContractTest extends TestCase
{
    public function test_list_thumbnail_uses_featured_image_projection(): void
    {
        $resource = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php',
        );

        self::assertStringContainsString('ArticleFeaturedImageProjection', $resource);
        self::assertStringContainsString('forList($record)', $resource);
        self::assertStringContainsString('ViewColumn::make(\'featured_thumb\')', $resource);
    }

    public function test_list_eager_loads_featured_attachment_meta_for_non_list_paths(): void
    {
        $resource = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php',
        );

        self::assertStringContainsString('META_FEATURED_ATTACHMENT_ID', $resource);
        self::assertStringContainsString("'wp_featured_image_url'", $resource);
    }

    public function test_list_select_keeps_site_id_and_projection_excludes_body(): void
    {
        $resource = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php',
        );

        self::assertStringContainsString("'articles.site_id'", $resource);
        self::assertStringContainsString("'articles.id'", $resource);
        self::assertStringContainsString('featured_thumb_url', $resource);
        self::assertStringNotContainsString("'articles.body'", $resource);
        self::assertStringNotContainsString("'articles.blocks'", $resource);
        self::assertStringNotContainsString("'articles.editor_document'", $resource);
    }

    public function test_resolver_is_db_only_no_wordpress_http_or_writes_or_post_images(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Support/ArticleFeaturedImageResolver.php',
        );

        self::assertStringContainsString('rebuildFromStoredSources', $source);
        self::assertStringContainsString('function forList', $source);
        self::assertDoesNotMatchRegularExpression(
            "/['\"]wp_post_images['\"]/",
            $source,
        );
        self::assertStringNotContainsString('Http::', $source);
        self::assertStringNotContainsString('fetchFromWordPress', $source);
        self::assertStringNotContainsString('WordPressArticleContentService', $source);
        self::assertStringNotContainsString('updateOrCreate', $source);
        self::assertStringNotContainsString('resolveEditorHtml', $source);
    }

    public function test_list_articles_still_blocks_wordpress_on_get_path(): void
    {
        $page = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/ListArticles.php',
        );
        $summary = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Support/ArticleListSeoSummary.php',
        );

        self::assertStringContainsString('countCachedForArticle', $summary);
        self::assertStringNotContainsString('fetchFromWordPress', $page);
        self::assertStringNotContainsString('WordPressArticleContentService', $summary);
    }
}
