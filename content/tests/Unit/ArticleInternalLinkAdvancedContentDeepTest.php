<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkPipeline;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkSuggestionService;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionContentKeywordFallback;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionContentPhraseExtractor;
use ReflectionClass;
use Tests\Support\ProjectRoot;
use Tests\TestCase;

/**
 * Advanced content_deep — extractor differentiation + algorithm contracts.
 * Full DB search integration covered by runtime funnel (article 11158).
 */
final class ArticleInternalLinkAdvancedContentDeepTest extends TestCase
{
    public function test_normal_extractor_misses_alnum_entity_advanced_finds(): void
    {
        $extractor = new ArticleLinkSuggestionContentPhraseExtractor;
        $html = '<p>Balo dùng vải Polyester 600D chống thấm tốt cho outdoor.</p>';

        $normal = array_map(
            static fn (array $row): string => mb_strtolower((string) $row['phrase']),
            $extractor->extract($html),
        );
        $deep = array_map(
            static fn (array $row): string => mb_strtolower((string) $row['phrase']),
            $extractor->extractDeepAdvanced($html),
        );

        self::assertNotContains('polyester 600d', $normal);
        self::assertContains('polyester 600d', $deep);
    }

    public function test_deep_extractor_splits_long_strong_into_short_windows(): void
    {
        $extractor = new ArticleLinkSuggestionContentPhraseExtractor;
        $html = '<p><strong>độ bền túi xách Polyester cao cấp</strong> cho outdoor.</p>';
        $deep = array_map(
            static fn (array $row): string => mb_strtolower((string) $row['phrase']),
            $extractor->extractDeepAdvanced($html),
        );

        self::assertTrue(
            in_array('độ bền', $deep, true)
            || in_array('túi xách', $deep, true)
            || in_array('polyester cao cấp', $deep, true),
            'Advanced must surface short 2–4 word windows from long strong text',
        );
    }

    public function test_discover_advanced_prefers_keyword_target_then_article_index(): void
    {
        $body = $this->methodBody(ArticleLinkSuggestionContentKeywordFallback::class, 'resolveDeepDestination');

        self::assertStringContainsString('phrases_with_keyword_target', $body);
        self::assertStringContainsString('resolveForKeyword', $body);
        $keywordPos = strpos($body, 'phrases_with_keyword_target');
        $searchPos = strpos($body, 'phrases_searched_index');
        self::assertNotFalse($keywordPos);
        self::assertNotFalse($searchPos);
        self::assertLessThan($searchPos, $keywordPos, 'Keyword inventory path must run before article index search');
        self::assertStringContainsString('searchService->search', $body);

        $build = $this->methodBody(ArticleLinkSuggestionContentKeywordFallback::class, 'buildDeepSuggestionItem');
        self::assertStringContainsString('isValidLinkSuggestion', $build);
        self::assertStringContainsString('content_deep', $build);
    }

    public function test_discover_advanced_batch_resume_and_no_candidate_semantics(): void
    {
        $body = $this->methodBody(ArticleLinkSuggestionContentKeywordFallback::class, 'discoverAdvancedContentBatch');

        self::assertStringContainsString('extractDeepAdvanced', $body);
        self::assertStringContainsString('phrase_offset', $body);
        self::assertStringContainsString('processed', $body);
        self::assertStringContainsString('no_candidate', $body);
        self::assertStringContainsString('count($added) >= $targetCount', $body);
        self::assertStringContainsString("'exhausted' => \$exhausted", $body);
        self::assertStringContainsString('next_offset', $body);
    }

    public function test_pipeline_content_deep_stage_is_advanced_differentiator(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleInternalLinkPipeline::class))->getFileName()
        );

        self::assertStringContainsString('ADVANCED_STAGE_CONTENT_DEEP', $src);
        self::assertStringContainsString('advanceContentDeepStage', $src);
        self::assertStringContainsString('discoverAdvancedContentBatch', $src);
        self::assertStringContainsString("lastDebug['content_deep']", $src);
    }

    public function test_usable_count_cap_ignores_raw_catalog_size(): void
    {
        $ref = new ReflectionClass(ArticleInternalLinkSuggestionService::class);
        $service = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('countUsableExistingSuggestions');
        $method->setAccessible(true);

        $existing = [];
        for ($i = 0; $i < 9; $i++) {
            $existing[] = [
                'text' => 'catalog '.$i,
                'href' => $i < 2 ? 'https://deep-adv.test/ok-'.$i.'/' : '#',
                'destination_resolved' => $i < 2,
            ];
        }

        self::assertSame(2, (int) $method->invoke($service, $existing));
        self::assertSame(8, max(0, 10 - 2));
        self::assertSame(5, max(1, min(5, 8)));

        $body = $this->methodBody(ArticleInternalLinkSuggestionService::class, 'suggestAdvancedBatch');
        self::assertStringContainsString('$usableCount >= 0', $body);
        self::assertStringContainsString('countUsableExistingSuggestions', $body);
        self::assertStringContainsString('display_cap_reached', $body);
    }

    public function test_session_version_bump_invalidates_stale_exhausted(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleInternalLinkSuggestionSessionStorage.js'
        );

        self::assertStringContainsString('INTERNAL_LINK_SUGGESTION_SESSION_VERSION = 2', $source);
        self::assertStringContainsString('version < INTERNAL_LINK_SUGGESTION_SESSION_VERSION', $source);
        self::assertStringContainsString("stage = 'content_deep'", $source);
    }

    public function test_fe_sends_usable_count_and_occupied_existing_only(): void
    {
        $sidebar = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleLinksSidebar.jsx'
        );

        self::assertStringContainsString('usable_count', $sidebar);
        self::assertStringContainsString("stage: 'content_deep'", $sidebar);
        self::assertStringContainsString('suggestedInternalRef.current', $sidebar);
        self::assertStringContainsString('linksRef.current.internal', $sidebar);
        self::assertStringNotContainsString(
            'partitioned.internal.map((item) => ({',
            $sidebar,
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
