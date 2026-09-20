<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkSearchService;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionContentKeywordFallback;
use Omnichannel\Addons\Seo\Support\LinkSuggestionScoreScale;
use ReflectionClass;
use Tests\TestCase;

/**
 * LIKE fallback relevance scoring — SSOT on ArticleInternalLinkSearchService.
 */
final class ArticleInternalLinkSearchLikeFallbackScoreTest extends TestCase
{
    private ArticleInternalLinkSearchService $search;

    protected function setUp(): void
    {
        parent::setUp();
        $this->search = app(ArticleInternalLinkSearchService::class);
    }

    public function test_a_exact_title_scores_above_fallback_min(): void
    {
        $scored = $this->search->scoreLikeFallbackRelevance('túi xách', 'túi xách');

        self::assertSame(LinkSuggestionScoreScale::TITLE_EXACT, $scored['score']);
        self::assertSame(ArticleInternalLinkSearchService::REASON_TITLE_EXACT, $scored['match_reason']);
        self::assertGreaterThan(LinkSuggestionScoreScale::fallbackMinAccept(), $scored['score']);
    }

    public function test_b_title_contains_full_phrase_passes_fallback_min(): void
    {
        $scored = $this->search->scoreLikeFallbackRelevance(
            'túi xách',
            'Cách chọn túi xách quà tặng doanh nghiệp',
        );

        self::assertSame(LinkSuggestionScoreScale::TITLE_CONTAINS, $scored['score']);
        self::assertSame(ArticleInternalLinkSearchService::REASON_TITLE_CONTAINS, $scored['match_reason']);
        self::assertGreaterThanOrEqual(LinkSuggestionScoreScale::fallbackMinAccept(), $scored['score']);
    }

    public function test_title_boundary_scores_between_exact_and_contains(): void
    {
        $scored = $this->search->scoreLikeFallbackRelevance(
            'túi xách',
            'túi xách quà tặng doanh nghiệp',
        );

        self::assertSame(ArticleInternalLinkSearchService::SCORE_TITLE_BOUNDARY, $scored['score']);
        self::assertSame(ArticleInternalLinkSearchService::REASON_TITLE_BOUNDARY, $scored['match_reason']);
        self::assertGreaterThan(LinkSuggestionScoreScale::TITLE_CONTAINS, $scored['score']);
        self::assertLessThan(LinkSuggestionScoreScale::TITLE_EXACT, $scored['score']);
    }

    public function test_c_weak_token_overlap_stays_below_fallback_min(): void
    {
        $scored = $this->search->scoreLikeFallbackRelevance(
            'túi xách',
            'túi balo đẹp cho outdoor',
        );

        self::assertSame(ArticleInternalLinkSearchService::REASON_TOKEN_OVERLAP, $scored['match_reason']);
        self::assertLessThan(LinkSuggestionScoreScale::fallbackMinAccept(), $scored['score']);
    }

    public function test_d_exact_title_ranks_above_weaker_contains_and_slug(): void
    {
        $exact = $this->search->scoreLikeFallbackRelevance('túi xách', 'túi xách');
        $contains = $this->search->scoreLikeFallbackRelevance(
            'túi xách',
            'Cách chọn túi xách quà tặng doanh nghiệp',
        );
        $slugOnly = $this->search->scoreLikeFallbackRelevance(
            'túi xách',
            'Bài viết khác không chứa cụm',
            'túi-xách-quà-tặng',
        );

        self::assertGreaterThan($contains['score'], $exact['score']);
        self::assertGreaterThan($slugOnly['score'], $contains['score']);
        self::assertSame(LinkSuggestionScoreScale::SLUG_MATCH, $slugOnly['score']);
        self::assertGreaterThanOrEqual(LinkSuggestionScoreScale::fallbackMinAccept(), $slugOnly['score']);
    }

    public function test_e_format_result_rejects_unresolved_destination_regardless_of_score(): void
    {
        $scored = $this->search->scoreLikeFallbackRelevance('túi xách', 'túi xách');
        self::assertGreaterThanOrEqual(LinkSuggestionScoreScale::fallbackMinAccept(), $scored['score']);

        $article = new SeoArticle;
        $article->forceFill([
            'id' => 9001,
            'title' => 'túi xách',
            'slug' => 'tui-xach',
            'site_id' => 6,
            'language' => 'vi',
        ]);
        $article->setRelation('articleMetas', collect());
        $article->setRelation('wordpressLink', null);
        $article->setRelation('site', null);

        $ref = new ReflectionClass($this->search);
        $method = $ref->getMethod('formatResult');
        $method->setAccessible(true);
        $formatted = $method->invoke($this->search, $article, 'túi xách');

        self::assertNull($formatted, 'High title relevance must not bypass unresolved destination');
    }

    public function test_f_advanced_content_deep_accepts_scored_like_fallback_when_ranked_empty(): void
    {
        // Scoring SSOT: strong title contains clears Advanced fallbackMinAccept (55).
        $scored = $this->search->scoreLikeFallbackRelevance(
            'túi xách chuyên dụng',
            'Hướng dẫn chọn túi xách chuyên dụng bền đẹp',
        );
        self::assertGreaterThanOrEqual(LinkSuggestionScoreScale::fallbackMinAccept(), $scored['score']);
        self::assertContains($scored['match_reason'], [
            ArticleInternalLinkSearchService::REASON_TITLE_CONTAINS,
            ArticleInternalLinkSearchService::REASON_TITLE_BOUNDARY,
            ArticleInternalLinkSearchService::REASON_TITLE_EXACT,
        ]);

        $searchSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleInternalLinkSearchService::class))->getFileName(),
        );
        $fallbackSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleLinkSuggestionContentKeywordFallback::class))->getFileName(),
        );

        // LIKE formatter now emits score at the search SSOT (not inside content_deep).
        self::assertStringContainsString("'score' => LinkSuggestionScoreScale::clamp", $searchSrc);
        self::assertStringContainsString('scoreLikeFallbackRelevance', $searchSrc);

        // content_deep consumes search scores and keeps the threshold gate.
        self::assertStringContainsString('searchService->search', $fallbackSrc);
        self::assertStringContainsString('fallbackMinAccept', $fallbackSrc);
        self::assertStringContainsString("trace['reject'] = 'below_min_score'", $fallbackSrc);
        self::assertStringContainsString('fresh_after_occupied_dedupe', $fallbackSrc);
        self::assertStringContainsString('destination_candidates_passing_min_score', $fallbackSrc);

        // Weak partial remains rejected by the same gate.
        $weak = $this->search->scoreLikeFallbackRelevance('túi xách', 'túi balo đẹp');
        self::assertLessThan(LinkSuggestionScoreScale::fallbackMinAccept(), $weak['score']);
    }

    public function test_f_content_deep_rejects_like_hit_below_fallback_min(): void
    {
        $weak = $this->search->scoreLikeFallbackRelevance(
            'túi xách',
            'túi balo đẹp cho outdoor',
        );
        self::assertLessThan(LinkSuggestionScoreScale::fallbackMinAccept(), $weak['score']);

        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleLinkSuggestionContentKeywordFallback::class))->getFileName(),
        );
        self::assertStringContainsString("trace['reject'] = 'below_min_score'", $src);
        self::assertStringContainsString('rejected_below_min_score', $src);
        self::assertStringContainsString('destination_candidates_passing_min_score', $src);
        self::assertStringContainsString('fallbackMinAccept', $src);
    }

    public function test_search_service_sorts_like_results_by_score_desc(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleInternalLinkSearchService::class))->getFileName(),
        );

        self::assertStringContainsString('scoreLikeFallbackRelevance', $src);
        self::assertStringContainsString('usort', $src);
        self::assertStringContainsString("\$b['score']", $src);
        self::assertStringContainsString('formatResult($article, $query)', $src);
    }
}
