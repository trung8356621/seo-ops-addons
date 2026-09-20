<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkPipeline;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkProductCatCatalog;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkSearchService;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionCandidateRetriever;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionContentKeywordFallback;
use Omnichannel\Addons\WordPress\Services\WordPressInternalLinkTargetPolicy;
use ReflectionClass;
use Tests\Support\ProjectRoot;
use Tests\TestCase;

/**
 * Language / destination-scope contracts for editor Internal Link search.
 */
final class ArticleInternalLinkSearchLanguageScopeTest extends TestCase
{
    public function test_manual_popup_like_fallback_does_not_use_article_resource_access_scopes(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleInternalLinkSearchService::class))->getFileName()
        );

        self::assertStringNotContainsString('ArticleResource::getEloquentQuery', $src);
        self::assertStringContainsString('SeoArticle::query()', $src);
        self::assertStringContainsString("->where('language', \$currentLanguage)", $src);
        self::assertStringContainsString('hasWpPostId()', $src);
        self::assertStringContainsString('notContentArchived()', $src);
    }

    public function test_site_article_index_filters_language_in_sql_not_only_memory(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleLinkSuggestionCandidateRetriever::class))->getFileName()
        );

        self::assertStringContainsString("->where('language', \$language)", $src);
        self::assertStringContainsString('siteIndexCacheKey($siteId, $language)', $src);
        self::assertStringContainsString("SITE_INDEX_CACHE_PREFIX", (string) file_get_contents(
            (new ReflectionClass(WordPressInternalLinkTargetPolicy::class))->getFileName()
        ));
    }

    public function test_search_ranked_uses_current_article_language(): void
    {
        $body = $this->methodBody(ArticleLinkSuggestionCandidateRetriever::class, 'searchRanked');

        self::assertStringContainsString("\$currentArticle->language", $body);
        self::assertStringContainsString('siteArticleIndex($siteId', $body);
    }

    public function test_content_deep_fresh_offset_ignores_prior_deep_processed_keys(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleInternalLinkPipeline::class))->getFileName()
        );

        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$offset\s*>\s*0\s*\)\s*\{[\s\S]*deep\|/',
            $src
        );
        self::assertStringContainsString('Fresh content_deep pass', $src);
    }

    public function test_content_deep_debug_counts_pre_search_skips(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleLinkSuggestionContentKeywordFallback::class))->getFileName()
        );

        self::assertStringContainsString("'skipped_already_processed'", $src);
        self::assertStringContainsString("'skipped_stop_phrase'", $src);
        self::assertStringContainsString("'skipped_occupied_anchor'", $src);
        self::assertStringContainsString("'skipped_empty'", $src);
    }

    public function test_product_cat_catalog_filters_by_source_article_language(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleInternalLinkProductCatCatalog::class))->getFileName()
        );

        self::assertStringContainsString('product_cat_catalog.v3.', $src);
        self::assertStringContainsString("\$sourceLanguage", $src);
        self::assertStringContainsString("'language'", $src);
        self::assertStringContainsString('product_cat_language_filtered', $src);

        $matcher = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleInternalLinkProductCatMatcher.php'
        );
        self::assertStringContainsString('?string $sourceLanguage = null', $matcher);
        self::assertStringContainsString('forSite($siteId, $sourceLanguage)', $matcher);
    }

    public function test_pipeline_passes_article_language_into_product_cat_stage(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleInternalLinkPipeline::class))->getFileName()
        );

        self::assertStringContainsString("\$article->language", $src);
        self::assertMatchesRegularExpression(
            '/matchForSite\(\s*\$siteId,\s*\$plainText,\s*\$validationContext,[\s\S]*\$article->language/',
            $src
        );
    }

    public function test_cache_key_is_language_scoped(): void
    {
        self::assertSame(
            'article_link_suggest.site_index.v4.6.vi',
            WordPressInternalLinkTargetPolicy::siteIndexCacheKey(6, 'vi'),
        );
        self::assertSame(
            'article_link_suggest.site_index.v4.6.en',
            WordPressInternalLinkTargetPolicy::siteIndexCacheKey(6, 'en'),
        );
        self::assertNotSame(
            WordPressInternalLinkTargetPolicy::siteIndexCacheKey(6, 'vi'),
            WordPressInternalLinkTargetPolicy::siteIndexCacheKey(6, 'en'),
        );
    }

    /**
     * @param  class-string  $class
     */
    private function methodBody(string $class, string $method): string
    {
        $ref = new ReflectionClass($class);
        $m = $ref->getMethod($method);
        $file = (string) $m->getFileName();
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));
    }
}
