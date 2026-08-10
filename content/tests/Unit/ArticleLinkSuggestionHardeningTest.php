<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Tests\Support\ProjectRoot;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkSuggestionService;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionCandidateRetriever;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionSearchTermsBuilder;
use Omnichannel\Addons\Seo\Support\LinkSuggestionValidator;
use Omnichannel\Addons\Seo\Support\SeoSuggestionUrlNormalizer;
use Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Link suggestion hardening — URL normalize, validation, search terms, self-link,
 * no keyword-only suggestions. Pure unit + source contracts (no DB).
 */
final class ArticleLinkSuggestionHardeningTest extends TestCase
{
    public function test_normalize_strips_www_protocol_and_trailing_slash(): void
    {
        $a = SeoSuggestionUrlNormalizer::normalize('https://www.Example.com/tui-vai/');
        $b = SeoSuggestionUrlNormalizer::normalize('http://example.com/tui-vai');

        self::assertSame($a, $b);
        self::assertSame('example.com/tui-vai', $a);
    }

    public function test_normalize_drops_fragment_and_tracking_query(): void
    {
        $normalized = SeoSuggestionUrlNormalizer::normalize(
            'https://example.com/path?utm_source=x&id=12#section',
        );

        self::assertSame('example.com/path?id=12', $normalized);
    }

    public function test_placeholder_urls_detected(): void
    {
        self::assertTrue(SeoSuggestionUrlNormalizer::isPlaceholder(''));
        self::assertTrue(SeoSuggestionUrlNormalizer::isPlaceholder('https://'));
        self::assertTrue(SeoSuggestionUrlNormalizer::isPlaceholder('http://'));
        self::assertFalse(SeoSuggestionUrlNormalizer::isPlaceholder('https://example.com/a'));
    }

    public function test_self_link_by_article_id(): void
    {
        self::assertTrue(LinkSuggestionValidator::isSelfLink(
            'https://example.com/other',
            ['target_article_id' => 10],
            ['current_article_id' => 10],
        ));
    }

    public function test_self_link_by_canonical_url_variants(): void
    {
        $context = [
            'current_article_id' => 1,
            'current_urls' => ['https://example.com/tui-vai-khong-det'],
            'current_slug' => 'tui-vai-khong-det',
            'site_domain' => 'example.com',
        ];

        self::assertTrue(LinkSuggestionValidator::isSelfLink(
            'http://www.example.com/tui-vai-khong-det/',
            [],
            $context,
        ));
    }

    public function test_self_link_by_slug_and_domain(): void
    {
        self::assertTrue(LinkSuggestionValidator::isSelfLink(
            'https://example.com/tui-vai-khong-det',
            [],
            [
                'current_slug' => 'tui-vai-khong-det',
                'site_domain' => 'example.com',
            ],
        ));
    }

    public function test_rejects_null_empty_and_placeholder_url(): void
    {
        $base = ['text' => 'túi vải', 'bucket' => 'internal', 'target_article_id' => 5];

        self::assertFalse(LinkSuggestionValidator::isValidLinkSuggestion(
            array_merge($base, ['href' => null]),
            ['current_article_id' => 1, 'site_domain' => 'example.com'],
        ));
        self::assertFalse(LinkSuggestionValidator::isValidLinkSuggestion(
            array_merge($base, ['href' => '']),
            ['current_article_id' => 1, 'site_domain' => 'example.com'],
        ));
        self::assertFalse(LinkSuggestionValidator::isValidLinkSuggestion(
            array_merge($base, ['href' => 'https://']),
            ['current_article_id' => 1, 'site_domain' => 'example.com'],
        ));
    }

    public function test_external_must_differ_from_site_domain(): void
    {
        self::assertFalse(LinkSuggestionValidator::isValidLinkSuggestion(
            [
                'text' => 'wiki',
                'href' => 'https://example.com/page',
                'bucket' => 'external',
            ],
            ['site_domain' => 'example.com', 'current_article_id' => 1],
        ));

        self::assertTrue(LinkSuggestionValidator::isValidLinkSuggestion(
            [
                'text' => 'wiki',
                'href' => 'https://en.wikipedia.org/wiki/Bag',
                'bucket' => 'external',
            ],
            ['site_domain' => 'example.com', 'current_article_id' => 1],
        ));
    }

    public function test_internal_relative_url_valid_when_not_self(): void
    {
        self::assertTrue(LinkSuggestionValidator::isValidLinkSuggestion(
            [
                'text' => 'túi vải không dệt',
                'href' => '/tui-vai-canvas',
                'target_article_id' => 9,
                'bucket' => 'internal',
            ],
            [
                'site_domain' => 'example.com',
                'current_article_id' => 1,
                'current_slug' => 'tui-vai-khong-det',
                'current_urls' => ['https://example.com/tui-vai-khong-det'],
            ],
        ));
    }

    public function test_search_terms_expand_anchor_and_context(): void
    {
        $builder = new ArticleLinkSuggestionSearchTermsBuilder;
        $terms = $builder->build('túi vải không dệt', [
            'title' => 'Hướng dẫn chọn túi vải',
            'focus_keyword' => 'túi vải',
            'paragraph_context' => 'in logo lên túi vải không dệt bằng kỹ thuật in lụa',
        ]);

        self::assertContains('túi vải không dệt', $terms);
        self::assertTrue(
            in_array('vải không dệt', $terms, true)
            || in_array('túi vải', $terms, true)
            || in_array('không dệt', $terms, true),
        );
        self::assertNotContains('và', $terms);
    }

    public function test_paragraph_context_extraction_around_anchor(): void
    {
        $builder = new ArticleLinkSuggestionSearchTermsBuilder;
        $plain = 'Mở đầu. Chúng tôi chuyên in logo lên túi vải bằng in lụa và in chuyển nhiệt. Kết.';
        $ctx = $builder->extractParagraphContext($plain, 'logo', 80);

        self::assertNotSame('', $ctx);
        self::assertTrue(str_contains($ctx, 'logo') || str_contains($ctx, 'in logo'));
    }

    public function test_suggestion_service_skips_empty_href_bucket(): void
    {
        $body = $this->methodBody(ArticleInternalLinkSuggestionService::class, 'suggestionBucketForHref');

        self::assertStringContainsString('isPlaceholder', $body);
        self::assertStringContainsString('return null', $body);
        self::assertStringNotContainsString('chờ gán bài đích', $body);
    }

    public function test_collect_candidates_requires_resolved_url_and_validates(): void
    {
        $body = $this->methodBody(ArticleInternalLinkSuggestionService::class, 'collectCandidates');

        self::assertStringContainsString('resolveBestForAnchors', $body);
        self::assertStringContainsString('isValidLinkSuggestion', $body);
        self::assertStringContainsString('Anchor candidate only', $body);
        self::assertStringContainsString('seenTargetArticleIds', $body);
        self::assertStringContainsString('usort(', $body);
        self::assertStringContainsString('$internalSuggestions', $body);
    }

    public function test_collect_candidates_uses_config_limits_not_hardcoded_class_const(): void
    {
        $ref = new ReflectionClass(ArticleInternalLinkSuggestionService::class);
        self::assertFalse($ref->hasConstant('MAX_INTERNAL_LINKS'));
        self::assertFalse($ref->hasConstant('MAX_SUGGESTION_DISPLAY'));

        $source = (string) file_get_contents((string) $ref->getFileName());
        self::assertStringContainsString("config('seo-content-ai.link_suggestions.", $source);
    }

    public function test_candidate_retriever_excludes_archived_and_self_in_index_query(): void
    {
        $body = $this->methodBody(ArticleLinkSuggestionCandidateRetriever::class, 'siteArticleIndex');

        self::assertStringContainsString("where('id', '!=', \$excludeArticleId)", $body);
        self::assertStringContainsString('whereNull(\'content_archived_at\')', $body);
        self::assertStringContainsString("with([", $body);
    }

    public function test_candidate_scoring_prefers_title_and_focus_keyword(): void
    {
        $retriever = (new ReflectionClass(ArticleLinkSuggestionCandidateRetriever::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ArticleLinkSuggestionCandidateRetriever::class, 'scoreCandidate');
        $method->setAccessible(true);

        $candidate = [
            'title_norm' => 'túi vải không dệt',
            'title_ascii' => 'tui vai khong det',
            'focus_norm' => 'túi giữ nhiệt',
            'slug_norm' => 'tui-vai-khong-det',
            'secondary_norms' => ['vải canvas'],
            'heading_norms' => ['kỹ thuật in lụa'],
            'meta_title_norm' => '',
            'meta_desc_norm' => '',
            'tag_norms' => [],
        ];

        $exact = $method->invoke($retriever, $candidate, 'túi vải không dệt', ['túi vải không dệt'], []);
        self::assertSame(100, $exact['score']);
        self::assertSame(ArticleLinkSuggestionCandidateRetriever::REASON_TITLE_EXACT, $exact['reason']);

        $secondary = $method->invoke($retriever, [
            'title_norm' => 'bài viết khác',
            'title_ascii' => 'bai viet khac',
            'focus_norm' => '',
            'slug_norm' => 'bai-viet-khac',
            'secondary_norms' => ['vải canvas cao cấp'],
            'heading_norms' => [],
            'meta_title_norm' => '',
            'meta_desc_norm' => '',
            'tag_norms' => [],
        ], 'vải canvas', ['vải canvas'], []);
        self::assertGreaterThanOrEqual(70, $secondary['score']);
        self::assertSame(ArticleLinkSuggestionCandidateRetriever::REASON_KEYWORD_MATCH, $secondary['reason']);

        $heading = $method->invoke($retriever, [
            'title_norm' => 'hướng dẫn sản xuất',
            'title_ascii' => 'huong dan san xuat',
            'focus_norm' => '',
            'slug_norm' => 'huong-dan',
            'secondary_norms' => [],
            'heading_norms' => ['kỹ thuật in lụa túi vải'],
            'meta_title_norm' => '',
            'meta_desc_norm' => '',
            'tag_norms' => [],
        ], 'in lụa', ['in lụa'], []);
        self::assertGreaterThanOrEqual(65, $heading['score']);
        self::assertSame(ArticleLinkSuggestionCandidateRetriever::REASON_HEADING_MATCH, $heading['reason']);

        $context = $method->invoke(
            $retriever,
            [
                'title_norm' => 'in chuyển nhiệt túi vải',
                'title_ascii' => 'in chuyen nhiet tui vai',
                'focus_norm' => '',
                'slug_norm' => 'in-chuyen-nhiet',
                'secondary_norms' => [],
                'heading_norms' => [],
                'meta_title_norm' => '',
                'meta_desc_norm' => '',
                'tag_norms' => [],
            ],
            'logo',
            ['logo', 'in logo', 'in chuyển nhiệt'],
            ['paragraph_context' => 'chúng tôi in logo bằng in chuyển nhiệt'],
        );
        self::assertGreaterThanOrEqual(40, $context['score']);
    }

    public function test_keywords_for_site_eager_loads_link_maps(): void
    {
        $body = $this->methodBody(ArticleInternalLinkSuggestionService::class, 'keywordsForSite');

        self::assertStringContainsString("->with([", $body);
        self::assertStringContainsString('linkMaps', $body);
    }

    public function test_config_defines_central_limits(): void
    {
        $configPath = ProjectRoot::path().DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'seo-content-ai.php';
        self::assertFileExists($configPath);
        $source = (string) file_get_contents($configPath);
        self::assertStringContainsString("'link_suggestions'", $source);
        self::assertStringContainsString("'max_candidates'", $source);
        self::assertStringContainsString("'min_accept_score'", $source);
    }

    public function test_ai_candidate_allowlist_rejects_unknown_id_and_invented_url(): void
    {
        self::assertFalse(LinkSuggestionValidator::isAllowedCandidateId(99, [1, 2, 3]));
        self::assertTrue(LinkSuggestionValidator::isAllowedCandidateId(2, [1, 2, 3]));

        self::assertFalse(LinkSuggestionValidator::isAllowedCandidateUrl(
            'https://evil.example/fake',
            ['https://example.com/real'],
        ));
        self::assertTrue(LinkSuggestionValidator::isAllowedCandidateUrl(
            'http://www.example.com/real/',
            ['https://example.com/real'],
        ));
    }

    /**
     * @param  class-string  $class
     */
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
