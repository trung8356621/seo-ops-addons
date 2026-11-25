<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleEditorLinksPayloadService;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkLexicalSuggester;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkSearchService;
use Omnichannel\Addons\Seo\Support\EditorAnchorOccurrenceMatcher;
use Omnichannel\Addons\Seo\Support\LinkSuggestionScoreScale;
use Omnichannel\Addons\Seo\Support\SeoSuggestionUrlNormalizer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Link Assistant — lexical-first / semantic-on-demand regression contracts.
 */
final class ArticleInternalLinkAssistantLexicalFirstPassTest extends TestCase
{
    public function test_01_search_sql_includes_focus_keyword_without_title_slug_gate(): void
    {
        $body = $this->methodBody(ArticleInternalLinkSearchService::class, 'search');

        self::assertStringContainsString('seo_focus_keyword', $body);
        self::assertStringContainsString('rank_math_focus_keyword', $body);
        self::assertStringContainsString("orWhereHas('articleMetas'", $body);
        self::assertStringContainsString("'title', 'like'", $body);
        self::assertStringContainsString("'slug', 'like'", $body);
    }

    public function test_02_focus_keyword_scores_without_title_match(): void
    {
        $search = (new ReflectionClass(ArticleInternalLinkSearchService::class))
            ->newInstanceWithoutConstructor();

        $scored = $search->scoreLikeFallbackRelevance(
            'túi vải không dệt',
            'Quy trình sản xuất balo quà tặng',
            'quy-trinh-san-xuat',
            'túi vải không dệt',
        );

        self::assertSame(ArticleInternalLinkSearchService::REASON_FOCUS_KEYWORD, $scored['match_reason']);
        self::assertSame(LinkSuggestionScoreScale::FOCUS_KEYWORD, $scored['score']);
    }

    public function test_03_domain_link_list_is_candidate_source(): void
    {
        $source = $this->classSource(ArticleInternalLinkLexicalSuggester::class);

        self::assertStringContainsString('DomainLinkListEditorService', $source);
        self::assertStringContainsString('domainLinkCandidates', $source);
        self::assertStringContainsString("'manual'", $source);
        self::assertStringContainsString("'product_category'", $source);
    }

    public function test_04_product_category_source_mapped(): void
    {
        $source = $this->classSource(ArticleInternalLinkLexicalSuggester::class);

        self::assertStringContainsString('SOURCE_PRODUCT_CAT', $source);
        self::assertStringContainsString('product_category', $source);
    }

    public function test_05_title_slug_paths_still_present(): void
    {
        $searchBody = $this->methodBody(ArticleInternalLinkSearchService::class, 'search');
        $lexical = $this->classSource(ArticleInternalLinkLexicalSuggester::class);

        self::assertStringContainsString("'title', 'like'", $searchBody);
        self::assertStringContainsString("'slug', 'like'", $searchBody);
        self::assertStringContainsString('title_exact', $lexical);
        self::assertStringContainsString('slug_match', $lexical);
    }

    public function test_06_cross_domain_not_classified_as_internal(): void
    {
        $service = (new ReflectionClass(ArticleInternalLinkLexicalSuggester::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ArticleInternalLinkLexicalSuggester::class, 'relationshipForUrl');
        $method->setAccessible(true);

        self::assertSame(
            'cross_domain',
            $method->invoke($service, 'https://maybalotuixachgiare.com/tui-canvas', 'baloquatang.net'),
        );
        self::assertSame(
            'internal',
            $method->invoke($service, 'https://baloquatang.net/tui-vai-khong-det', 'baloquatang.net'),
        );
        self::assertSame(
            'internal',
            $method->invoke($service, '/relative/path', 'baloquatang.net'),
        );
    }

    public function test_07_first_pass_never_calls_semantic_service(): void
    {
        $lexical = $this->methodBody(ArticleEditorLinksPayloadService::class, 'suggestLexicalOnly');
        $merge = $this->methodBody(ArticleEditorLinksPayloadService::class, 'mergeSemanticSuggestions');

        self::assertStringNotContainsString('InternalLinkV2Suggester', $lexical);
        self::assertStringContainsString('suggestLexicalOnly', $merge);
        self::assertStringNotContainsString('suggestSemanticOnly', $merge);
        self::assertStringNotContainsString('InternalLinkV2Suggester', $merge);
    }

    public function test_08_empty_lexical_does_not_auto_fallback_semantic(): void
    {
        $lexical = $this->methodBody(ArticleEditorLinksPayloadService::class, 'suggestLexicalOnly');

        self::assertStringContainsString('ArticleInternalLinkLexicalSuggester::class', $lexical);
        self::assertStringNotContainsString('InternalLinkV2Suggester', $lexical);
        self::assertDoesNotMatchRegularExpression('/if\s*\(.*suggestions.*\[\].*\)/', $lexical);
    }

    public function test_09_second_pass_calls_semantic_v2(): void
    {
        $fallback = $this->methodBody(ArticleEditorLinksPayloadService::class, 'withFallbackOnly');
        $semantic = $this->methodBody(ArticleEditorLinksPayloadService::class, 'suggestSemanticOnly');

        self::assertStringContainsString('suggestSemanticOnly', $fallback);
        self::assertStringContainsString('InternalLinkV2Suggester::class', $semantic);
        self::assertStringContainsString("'semantic_v2'", $semantic);
    }

    public function test_10_second_pass_excludes_existing_urls_via_occupied(): void
    {
        $fallback = $this->methodBody(ArticleEditorLinksPayloadService::class, 'withFallbackOnly');

        self::assertStringContainsString('$occupied', $fallback);
        self::assertStringContainsString('existingInternal', $fallback);
        self::assertStringContainsString('suggestSemanticOnly($article, $content, $occupied, $cursor)', $fallback);
    }

    public function test_11_self_link_and_invalid_permalink_rejected(): void
    {
        $source = $this->classSource(ArticleInternalLinkLexicalSuggester::class);

        self::assertStringContainsString('self_link', $source);
        self::assertStringContainsString('no_authoritative_permalink', $source);
        self::assertStringContainsString('invalid_destination', $source);
        self::assertStringContainsString('isPlaceholder', $source);
    }

    public function test_12_duplicate_normalized_url_deduped(): void
    {
        $source = $this->classSource(ArticleInternalLinkLexicalSuggester::class);

        self::assertStringContainsString('SeoSuggestionUrlNormalizer::normalize', $source);
        self::assertStringContainsString("'duplicate'", $source);

        $a = SeoSuggestionUrlNormalizer::normalize('https://baloquatang.net/tui-canvas/');
        $b = SeoSuggestionUrlNormalizer::normalize('https://www.baloquatang.net/tui-canvas');
        self::assertSame($a, $b);
    }

    public function test_13_vietnamese_accent_anchor_match(): void
    {
        $items = [
            [
                'text' => 'túi vải không dệt',
                'href' => 'https://baloquatang.net/tui-vai-khong-det',
                'target_url' => 'https://baloquatang.net/tui-vai-khong-det',
            ],
        ];
        $html = '<p>Chúng tôi sản xuất túi vải không dệt và túi canvas.</p>';
        $matched = EditorAnchorOccurrenceMatcher::filterActionable($items, $html);

        self::assertCount(1, $matched);
        self::assertSame('túi vải không dệt', $matched[0]['text']);
    }

    public function test_14_no_hash_or_pending_href_in_lexical_pipeline(): void
    {
        $source = $this->classSource(ArticleInternalLinkLexicalSuggester::class);
        $publish = $this->methodBody(ArticleEditorLinksPayloadService::class, 'withoutFragmentSuggestions');

        self::assertStringContainsString("\$url === '#'", $source);
        self::assertStringContainsString('isPlaceholder', $source);
        self::assertStringContainsString("str_starts_with(\$href, '#')", $publish);
    }

    public function test_15_domain_link_list_read_only_not_mutated(): void
    {
        $source = $this->classSource(ArticleInternalLinkLexicalSuggester::class);

        self::assertStringContainsString('->forSite(', $source);
        self::assertStringNotContainsString('->save(', $source);
        self::assertStringNotContainsString('updateOrCreate', $source);
        self::assertStringNotContainsString('DomainLinkListKeywordSyncService', $source);
    }

    public function test_16_article_catalog_scan_is_bounded(): void
    {
        $source = $this->classSource(ArticleInternalLinkLexicalSuggester::class);

        self::assertStringContainsString('MAX_ARTICLE_DESTINATIONS = 250', $source);
        self::assertStringContainsString('->limit(self::MAX_ARTICLE_DESTINATIONS)', $source);
        self::assertStringContainsString('orderByDesc(\'updated_at\')', $source);
    }

    private function classSource(string $class): string
    {
        return (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
    }

    private function methodBody(string $class, string $method): string
    {
        $ref = new ReflectionClass($class);
        $m = $ref->getMethod($method);
        $lines = explode("\n", (string) file_get_contents((string) $ref->getFileName()));

        return implode("\n", array_slice(
            $lines,
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1,
        ));
    }
}
