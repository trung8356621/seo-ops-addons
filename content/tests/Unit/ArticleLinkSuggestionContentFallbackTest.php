<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Tests\Support\ProjectRoot;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkSearchService;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkSuggestionService;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionContentKeywordFallback;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionContentPhraseExtractor;
use Tests\TestCase;
use ReflectionClass;

/**
 * Content-keyword fallback — extract phrases + gate khi primary < target.
 * Pure unit + source contracts (no DB / no AI).
 */
final class ArticleLinkSuggestionContentFallbackTest extends TestCase
{
    public function test_should_run_only_when_below_target(): void
    {
        $body = $this->methodBody(ArticleLinkSuggestionContentKeywordFallback::class, 'shouldRun');
        self::assertStringContainsString('fallback_enabled', $body);
        self::assertStringContainsString('targetCount()', $body);
        self::assertStringContainsString('$currentInternalCount <', $body);
    }

    public function test_collect_candidates_invokes_fallback_when_short(): void
    {
        $body = $this->methodBody(ArticleInternalLinkSuggestionService::class, 'collectCandidates');

        self::assertStringContainsString('contentKeywordFallback', $body);
        self::assertStringContainsString('shouldRun(count($internalSuggestions))', $body);
        self::assertStringContainsString('->supplement(', $body);
        self::assertStringContainsString("'source' => 'primary'", $body);
    }

    public function test_fallback_reuses_popup_search_service(): void
    {
        $ref = new ReflectionClass(ArticleLinkSuggestionContentKeywordFallback::class);
        $ctor = $ref->getConstructor();
        self::assertNotNull($ctor);
        $params = $ctor->getParameters();
        $types = array_map(
            static fn ($p) => $p->getType()?->getName(),
            $params,
        );
        self::assertContains(ArticleInternalLinkSearchService::class, $types);
        self::assertContains(ArticleLinkSuggestionContentPhraseExtractor::class, $types);

        $body = $this->methodBody(ArticleLinkSuggestionContentKeywordFallback::class, 'supplement');
        self::assertStringContainsString('$this->searchService->search(', $body);
        self::assertStringContainsString('resolveKeywordIdForPhrase', $body);
        self::assertStringNotContainsString("'keyword_id' => null", $body);
        self::assertStringNotContainsString('OpenAI', $body);
        self::assertStringNotContainsString('embedding', $body);
        self::assertStringNotContainsString('PromptRunner', $body);
    }

    public function test_fallback_stops_at_target_and_keeps_threshold(): void
    {
        $body = $this->methodBody(ArticleLinkSuggestionContentKeywordFallback::class, 'supplement');

        self::assertStringContainsString('fallback_min_score', $body);
        self::assertStringContainsString('$score < $minScore', $body);
        self::assertStringContainsString('count($added) >= $needed', $body);
        self::assertStringContainsString('isValidLinkSuggestion', $body);
        self::assertStringContainsString('isPlaceholder', $body);
        self::assertStringContainsString("'source' => 'content_keyword_fallback'", $body);
    }

    public function test_extractor_skips_stop_phrase_lien_he(): void
    {
        $extractor = new ArticleLinkSuggestionContentPhraseExtractor;
        $html = '<p>Vui lòng <strong>liên hệ</strong> với chúng tôi về balo chống nước.</p>';
        $phrases = $extractor->extract($html);

        $normalized = array_map(
            static fn (array $row): string => mb_strtolower($row['phrase']),
            $phrases,
        );
        self::assertNotContains('liên hệ', $normalized);
    }

    public function test_extractor_skips_single_generic_token(): void
    {
        $extractor = new ArticleLinkSuggestionContentPhraseExtractor;
        $html = '<p>Mua balo và túi và vải ngay hôm nay.</p>';
        $phrases = $extractor->extract($html, [], []);

        foreach ($phrases as $row) {
            $words = preg_split('/\s+/u', trim($row['phrase'])) ?: [];
            if (count($words) === 1) {
                self::assertGreaterThan(3, mb_strlen($words[0]));
            }
        }
    }

    public function test_extractor_skips_text_inside_anchor(): void
    {
        $extractor = new ArticleLinkSuggestionContentPhraseExtractor;
        $html = '<p>Xem <a href="/x">balo chống nước</a> và chọn ngăn chống sốc phù hợp.</p>';
        $phrases = $extractor->extract($html);
        $texts = array_map(static fn (array $r): string => mb_strtolower($r['phrase']), $phrases);

        self::assertNotContains('balo chống nước', $texts);
        self::assertTrue(
            in_array('ngăn chống sốc', $texts, true)
            || $extractor->findVerbatimOccurrence($html, 'ngăn chống sốc') !== null,
        );
    }

    public function test_normalize_anchor_trims_edge_punctuation_only(): void
    {
        $extractor = new ArticleLinkSuggestionContentPhraseExtractor;

        self::assertSame('Chất liệu vải cao cấp', $extractor->normalizeAnchorText('Chất liệu vải cao cấp:'));
        self::assertSame('Vệ sinh đúng cách', $extractor->normalizeAnchorText('Vệ sinh đúng cách:'));
        self::assertSame('Balo chống nước', $extractor->normalizeAnchorText('- Balo chống nước'));
        self::assertSame('Khóa kéo YKK', $extractor->normalizeAnchorText('(Khóa kéo YKK)'));
        self::assertSame('Máy tính xách tay', $extractor->normalizeAnchorText('Máy tính xách tay,'));
        self::assertSame('Balo chống nước', $extractor->normalizeAnchorText('   Balo chống nước   '));

        // Giữ ký tự giữa cụm.
        self::assertSame('USB-C', $extractor->normalizeAnchorText('USB-C'));
        self::assertSame('Wi-Fi', $extractor->normalizeAnchorText('Wi-Fi'));
        self::assertSame('TP.HCM', $extractor->normalizeAnchorText('TP.HCM'));
        self::assertSame('2-in-1', $extractor->normalizeAnchorText('2-in-1'));
        self::assertSame('Laptop 14-inch', $extractor->normalizeAnchorText('Laptop 14-inch'));
    }

    public function test_normalize_rejects_short_cta_anchors(): void
    {
        $extractor = new ArticleLinkSuggestionContentPhraseExtractor;
        $html = '<p><strong>Xem thêm:</strong> và <strong>Chi tiết</strong> cùng <strong>ngăn chống sốc:</strong></p>';
        $phrases = $extractor->extract($html);
        $texts = array_map(static fn (array $r): string => mb_strtolower($r['phrase']), $phrases);

        self::assertNotContains('xem thêm', $texts);
        self::assertNotContains('chi tiết', $texts);
        self::assertContains('ngăn chống sốc', $texts);
    }

    public function test_extractor_prefers_highlight_over_heading(): void
    {
        $extractor = new ArticleLinkSuggestionContentPhraseExtractor;
        $html = '<h2>Hướng dẫn bảo quản balo bền đẹp</h2><p>Nên chọn <strong>ngăn chống sốc</strong> dày.</p>';
        $phrases = $extractor->extract($html);
        self::assertNotSame([], $phrases);
        self::assertSame('strong', $phrases[0]['source']);
        self::assertSame('ngăn chống sốc', mb_strtolower($phrases[0]['phrase']));
        self::assertGreaterThanOrEqual(
            ArticleLinkSuggestionContentPhraseExtractor::SCORE_STRONG,
            (int) ($phrases[0]['source_score'] ?? 0),
        );
    }

    public function test_heading_prefix_stripped_to_core_phrase(): void
    {
        $extractor = new ArticleLinkSuggestionContentPhraseExtractor;
        self::assertSame(
            'bảo quản balo bền đẹp',
            $extractor->stripHeadingPrefix('Hướng dẫn bảo quản balo bền đẹp'),
        );

        $html = '<h2>Hướng dẫn bảo quản balo bền đẹp</h2><p>Nội dung không highlight.</p>';
        $phrases = $extractor->extract($html);
        $texts = array_map(static fn (array $r): string => mb_strtolower($r['phrase']), $phrases);
        self::assertNotContains('hướng dẫn bảo quản balo bền đẹp', $texts);
        self::assertTrue(
            in_array('bảo quản balo', $texts, true)
            || in_array('bảo quản balo bền đẹp', $texts, true)
            || in_array('bảo quản balo bền', $texts, true),
            'Expected stripped heading core, got: '.implode(' | ', $texts),
        );
    }

    public function test_verbatim_occurrence_rejects_invented_anchor(): void
    {
        $extractor = new ArticleLinkSuggestionContentPhraseExtractor;
        $html = '<p>ngăn chuyên dụng chống sốc cho balo laptop</p>';

        self::assertNotNull($extractor->findVerbatimOccurrence($html, 'ngăn chuyên dụng chống sốc'));
        self::assertNull($extractor->findVerbatimOccurrence($html, 'ngăn chống sốc cao cấp'));
    }

    public function test_fallback_rejects_empty_url_paths_in_source(): void
    {
        $body = $this->methodBody(ArticleLinkSuggestionContentKeywordFallback::class, 'supplement');

        self::assertStringContainsString('$href === \'\'', $body);
        self::assertStringContainsString('targetId <= 0', $body);
        self::assertStringContainsString('isset($seenTargets[$targetId])', $body);
        self::assertStringContainsString('in_array($normalizedHref, $seenUrls', $body);
        self::assertStringContainsString('$targetId === $excludeId', $body);
    }

    public function test_config_has_fallback_knobs_and_stop_phrases(): void
    {
        $configPath = ProjectRoot::path().DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'seo-content-ai.php';
        self::assertFileExists($configPath);
        $source = (string) file_get_contents($configPath);

        self::assertStringContainsString("'target_internal_suggestions'", $source);
        self::assertStringContainsString("'fallback_candidate_limit'", $source);
        self::assertStringContainsString("'fallback_phrase_limit'", $source);
        self::assertStringContainsString("'fallback_min_score'", $source);
        self::assertStringContainsString("'fallback_stop_phrases'", $source);
        self::assertStringContainsString('liên hệ', $source);
    }

    public function test_no_hardcoded_target_five_in_fallback_service(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticleLinkSuggestionContentKeywordFallback::class))->getFileName(),
        );
        self::assertStringNotContainsString('>= 5', $source);
        self::assertStringNotContainsString('< 5', $source);
        self::assertStringContainsString('target_internal_suggestions', $source);
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
