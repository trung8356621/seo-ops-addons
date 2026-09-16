<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkSearchService;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionCandidateRetriever;
use Omnichannel\Addons\Content\Services\ArticlePendingInternalLinkService;
use Omnichannel\Addons\SearchFoundation\Services\KeywordLinkTargetResolver;
use Omnichannel\Addons\SearchFoundation\Services\RepairArticleBackedKeywordTargetUrlService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Omnichannel\Addons\WordPress\Services\WordPressInternalLinkTargetPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Internal-link URL SoT contracts — WordPress permalink only, no domain+slug, no draft candidates.
 */
final class InternalLinkWordPressUrlPolicyContractTest extends TestCase
{
    public function test_candidate_index_allows_unsynced_semantic_pool_and_uses_v3_cache(): void
    {
        $body = $this->methodBody(ArticleLinkSuggestionCandidateRetriever::class, 'siteArticleIndex');

        self::assertStringNotContainsString('hasWpPostId()', $body);
        self::assertStringContainsString('destination_resolved', $body);
        self::assertStringContainsString('WordPressInternalLinkTargetPolicy::siteIndexCacheKey', $body);
        self::assertStringContainsString("'wordpressLink'", $body);
        self::assertStringNotContainsString('site_index.v1.', $body);
        self::assertStringNotContainsString('getPermalinkBase', $body);

        $policySrc = (string) file_get_contents(
            (string) (new ReflectionClass(WordPressInternalLinkTargetPolicy::class))->getFileName(),
        );
        self::assertStringContainsString('site_index.v3.', $policySrc);
    }

    public function test_popup_search_ranked_and_fallback_require_wp_sync(): void
    {
        $searchSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleInternalLinkSearchService::class))->getFileName(),
        );

        self::assertStringContainsString('hasWpPostId()', $searchSrc);
        self::assertStringContainsString('WordPressInternalLinkTargetPolicy', $searchSrc);
        self::assertStringContainsString('resolveAuthoritativePermalink', $searchSrc);
        self::assertStringNotContainsString('getPermalinkBase', $searchSrc);
        self::assertStringNotContainsString("\$base.'/'.ltrim(\$slug", $searchSrc);
        self::assertStringNotContainsString('WordPressPermalinkBuilder', $searchSrc);
    }

    public function test_keyword_resolver_prefers_focus_article_over_stale_target_url(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordLinkTargetResolver::class))->getFileName(),
        );
        $resolveBody = $this->methodBody(KeywordLinkTargetResolver::class, 'resolveForKeyword');
        $publicUrlBody = $this->methodBody(KeywordLinkTargetResolver::class, 'resolveArticlePublicUrl');

        self::assertStringContainsString('resolveFocusArticleDestination', $resolveBody);
        self::assertLessThan(
            strpos($resolveBody, 'targetUrlForSite') ?: PHP_INT_MAX,
            strpos($resolveBody, 'resolveFocusArticleDestination') ?: PHP_INT_MAX,
        );
        self::assertStringContainsString('mainArticleIdForSite', $src);
        self::assertStringContainsString('WordPressInternalLinkTargetPolicy', $src);
        self::assertStringContainsString('resolveAuthoritativePermalink', $publicUrlBody);
        self::assertStringNotContainsString('getPermalinkBase', $src);
        self::assertStringNotContainsString("rtrim(\$base, '/').'/'.ltrim(\$slug", $src);
    }

    public function test_focus_attach_does_not_write_fabricated_target_url(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordFocusAttach::class))->getFileName(),
        );

        self::assertStringContainsString('setSiteTargetUrl($keywordId, $siteId, null)', $src);
        self::assertStringContainsString('wpPostId > 0', $src);
        self::assertStringNotContainsString('resolvePermalink($article)', $src);
        self::assertStringNotContainsString('WordPressArticleContentService', $src);
    }

    public function test_pending_resolve_uses_authoritative_wordpress_permalink_only(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticlePendingInternalLinkService::class))->getFileName(),
        );

        self::assertStringContainsString('WordPressInternalLinkTargetPolicy', $src);
        self::assertStringContainsString('resolveAuthoritativePermalink', $src);
        self::assertStringNotContainsString('getPermalinkBase', $src);
        self::assertStringNotContainsString('WordPressPermalinkBuilder', $src);
    }

    public function test_content_fallback_reuses_search_which_enforces_eligibility(): void
    {
        $fallback = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\Content\Services\ArticleLinkSuggestionContentKeywordFallback::class))->getFileName(),
        );
        $search = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleInternalLinkSearchService::class))->getFileName(),
        );

        self::assertStringContainsString('$this->searchService->search(', $fallback);
        self::assertStringContainsString('hasWpPostId()', $search);
    }

    public function test_repair_service_only_clears_article_backed_target_urls(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(RepairArticleBackedKeywordTargetUrlService::class))->getFileName(),
        );

        self::assertStringContainsString('getMainArticleIdForSite', $src);
        self::assertStringContainsString('setSiteTargetUrl($keywordId, $metaSiteId, null)', $src);
        self::assertStringContainsString('skipped_manual', $src);
        self::assertStringContainsString('skipped_cross_site', $src);
        self::assertStringContainsString('site.%.target_url', $src);
    }

    public function test_policy_source_never_builds_domain_plus_slug(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(WordPressInternalLinkTargetPolicy::class))->getFileName(),
        );

        self::assertStringContainsString('observed_permalink', $src);
        self::assertStringContainsString('wp_permalink', $src);
        self::assertStringContainsString('wp_post_id', $src);
        self::assertStringNotContainsString('getPermalinkBase', $src);
        self::assertStringNotContainsString("ltrim(\$slug", $src);
        self::assertStringNotContainsString('articles.slug', $src);
        self::assertStringNotContainsString("'/'.ltrim", $src);
    }

    /**
     * @param  class-string  $class
     */
    private function methodBody(string $class, string $method): string
    {
        $ref = new ReflectionClass($class);
        $m = new ReflectionMethod($class, $method);
        $lines = explode("\n", (string) file_get_contents((string) $ref->getFileName()));

        return implode("\n", array_slice(
            $lines,
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1,
        ));
    }
}
