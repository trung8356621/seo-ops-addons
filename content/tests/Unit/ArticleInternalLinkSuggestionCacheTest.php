<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkSuggestionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Source/contract tests (no DB) — Phase 2 perf: collectCandidates() must be
 * request-scoped cached (by article+content+links) and the site keyword scan
 * must be cached per (siteId, excludeKeywordIds) so a single request never
 * re-runs the keyword query for suggest()/suggestCatalog()/suggestExternal()/
 * suggestExternalCatalog() on the same article.
 */
final class ArticleInternalLinkSuggestionCacheTest extends TestCase
{
    public function test_service_declares_request_scoped_caches(): void
    {
        $ref = new ReflectionClass(ArticleInternalLinkSuggestionService::class);

        self::assertTrue($ref->hasProperty('candidatesCache'));
        self::assertTrue($ref->hasProperty('keywordsBySite'));
    }

    public function test_collect_candidates_reads_and_writes_candidates_cache(): void
    {
        $body = $this->methodBody('collectCandidates');

        self::assertStringContainsString('$this->candidatesCache[$cacheKey]', $body);
        self::assertStringContainsString('candidatesCacheKey(', $body);
    }

    public function test_collect_candidates_delegates_keyword_scan_to_cached_helper(): void
    {
        $body = $this->methodBody('collectCandidates');

        self::assertStringContainsString('$this->keywordsForSite(', $body);
        // The raw Keyword::query() scan itself must live only inside keywordsForSite(),
        // not be duplicated inline in collectCandidates().
        self::assertStringNotContainsString('Keyword::query()', $body);
    }

    public function test_keywords_for_site_caches_by_site_and_excluded_ids(): void
    {
        $body = $this->methodBody('keywordsForSite');

        self::assertStringContainsString('$this->keywordsBySite[$cacheKey]', $body);
        self::assertStringContainsString('Keyword::query()', $body);
    }

    public function test_suggest_bundle_calls_collect_candidates_exactly_once(): void
    {
        $body = $this->methodBody('suggestBundle');

        self::assertSame(1, substr_count($body, 'collectCandidates('));
    }

    private function methodBody(string $method): string
    {
        $ref = new ReflectionClass(ArticleInternalLinkSuggestionService::class);
        $m = $ref->getMethod($method);
        $lines = explode("\n", (string) file_get_contents((string) $ref->getFileName()));

        return implode("\n", array_slice(
            $lines,
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1,
        ));
    }
}
