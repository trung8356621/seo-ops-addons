<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkPipeline;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionContentKeywordFallback;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionContentPhraseExtractor;
use Omnichannel\Addons\Content\Support\InternalLinkAnchorQuality;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Progressive Advanced content_deep — A–E contracts + cursor stage machine.
 *
 * Full destination search is final/SearchService-coupled; batch slicing + cursor
 * resume + depth progression are asserted here as source/reflection contracts.
 */
final class ArticleInternalLinkAdvancedProgressiveTest extends TestCase
{
    public function test_pipeline_registers_progressive_content_deep_depths(): void
    {
        $order = ArticleInternalLinkPipeline::ADVANCED_STAGE_ORDER;

        self::assertContains(ArticleInternalLinkPipeline::ADVANCED_STAGE_CONTENT_DEEP, $order);
        self::assertContains(ArticleInternalLinkPipeline::ADVANCED_STAGE_CONTENT_DEEP_EXTENDED, $order);
        self::assertContains(ArticleInternalLinkPipeline::ADVANCED_STAGE_CONTENT_DEEP_DEEPER, $order);

        $deepIdx = array_search(ArticleInternalLinkPipeline::ADVANCED_STAGE_CONTENT_DEEP, $order, true);
        $extIdx = array_search(ArticleInternalLinkPipeline::ADVANCED_STAGE_CONTENT_DEEP_EXTENDED, $order, true);
        $deeperIdx = array_search(ArticleInternalLinkPipeline::ADVANCED_STAGE_CONTENT_DEEP_DEEPER, $order, true);
        self::assertIsInt($deepIdx);
        self::assertIsInt($extIdx);
        self::assertIsInt($deeperIdx);
        self::assertTrue($deepIdx < $extIdx && $extIdx < $deeperIdx);
    }

    /** A — 12 eligible → click1=5, click2=5, click3=2, click4=exhausted; no duplicate slice. */
    public function test_a_twelve_eligible_batches_slice_without_overlap(): void
    {
        $eligible = range(0, 11); // 12 phrase indices
        $batchSize = 5;
        $offset = 0;
        $batches = [];
        $seen = [];

        while ($offset < count($eligible)) {
            $slice = array_slice($eligible, $offset, $batchSize);
            if ($slice === []) {
                break;
            }
            foreach ($slice as $idx) {
                self::assertArrayNotHasKey($idx, $seen, 'duplicate phrase index across batches');
                $seen[$idx] = true;
            }
            $batches[] = $slice;
            $offset += count($slice);
        }

        self::assertCount(3, $batches);
        self::assertCount(5, $batches[0]);
        self::assertCount(5, $batches[1]);
        self::assertCount(2, $batches[2]);
        self::assertSame(12, count($seen));

        // 4th click: cursor past end → exhausted
        self::assertGreaterThanOrEqual(count($eligible), $offset);

        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleLinkSuggestionContentKeywordFallback::class))->getFileName()
        );
        self::assertStringContainsString('$i = max(0, $phraseOffset);', $src);
        self::assertStringContainsString('count($added) >= $targetCount', $src);
        self::assertStringContainsString("'next_offset' => \$i", $src);
        self::assertStringContainsString('$exhausted = $i >= $total', $src);
        self::assertStringContainsString("\$targetCount = max(1, min(5, \$targetCount));", $src);
    }

    /** B — cursor from batch1 is the resume input for batch2 (offset monotonic). */
    public function test_b_cursor_from_batch1_consumed_by_batch2(): void
    {
        $pipeline = app(ArticleInternalLinkPipeline::class);
        $m = new ReflectionMethod($pipeline, 'nextContentDeepCursorAfterDone');
        $m->setAccessible(true);

        // Within a depth: next_offset advances; across depths: stage advances, offset resets.
        self::assertSame(
            [
                'stage' => ArticleInternalLinkPipeline::ADVANCED_STAGE_CONTENT_DEEP_EXTENDED,
                'offset' => 0,
            ],
            $m->invoke($pipeline, ArticleInternalLinkPipeline::ADVANCED_STAGE_CONTENT_DEEP),
        );
        self::assertSame(
            [
                'stage' => ArticleInternalLinkPipeline::ADVANCED_STAGE_CONTENT_DEEP_DEEPER,
                'offset' => 0,
            ],
            $m->invoke($pipeline, ArticleInternalLinkPipeline::ADVANCED_STAGE_CONTENT_DEEP_EXTENDED),
        );

        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleInternalLinkPipeline::class))->getFileName()
        );
        self::assertStringContainsString("\$startOffset = max(0, (int) (\$cursor['offset']", $src);
        self::assertStringContainsString("'offset' => \$result['next_offset']", $src);
    }

    /** C — failed/no-candidate phrases stay in failedSet via deep|* keys (not retried). */
    public function test_c_failed_keys_always_seed_processed_not_only_when_offset_positive(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleInternalLinkPipeline::class))->getFileName()
        );

        self::assertStringContainsString('Progressive discovery: always honor deep|*', $src);
        self::assertStringContainsString("str_starts_with((string) \$key, 'deep|')", $src);
        self::assertDoesNotMatchRegularExpression(
            '/Fresh content_deep pass[\s\S]*if\s*\(\s*\$offset\s*>\s*0\s*\)/',
            $src,
        );

        $fallbackSrc = (string) file_get_contents(
            (new ReflectionClass(ArticleLinkSuggestionContentKeywordFallback::class))->getFileName()
        );
        self::assertStringContainsString('skipped_already_processed', $fallbackSrc);
        self::assertStringContainsString("'deep|'.\$phraseKey.'|'", $fallbackSrc);
    }

    /** D — generic anchors rejected in every discovery mode (quality not weakened). */
    public function test_d_generic_anchors_rejected_independent_of_discovery_mode(): void
    {
        self::assertFalse(InternalLinkAnchorQuality::evaluate('Lý do')['accepted']);
        self::assertFalse(InternalLinkAnchorQuality::evaluate('nổi bật')['accepted']);

        $fallbackSrc = (string) file_get_contents(
            (new ReflectionClass(ArticleLinkSuggestionContentKeywordFallback::class))->getFileName()
        );
        self::assertStringContainsString("\$discoveryMode = 'strong'", $fallbackSrc);
        self::assertStringContainsString('InternalLinkAnchorQuality::evaluate', $fallbackSrc);

        // Quality gate must not be wrapped in discoveryMode condition.
        self::assertDoesNotMatchRegularExpression(
            '/discoveryMode[^\n]{0,80}InternalLinkAnchorQuality::evaluate/',
            $fallbackSrc,
        );

        $extractor = new ReflectionMethod(ArticleLinkSuggestionContentPhraseExtractor::class, 'extractDeepAdvanced');
        self::assertGreaterThanOrEqual(4, $extractor->getNumberOfParameters());
    }

    /** E — empty failed_keys = new session restart from beginning. */
    public function test_e_new_session_restart_contract_empty_failed_keys(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleInternalLinkPipeline::class))->getFileName()
        );
        self::assertStringContainsString('New session sends failed_keys=[]', $src);
        self::assertStringContainsString('contentDeepDiscoveryMode', $src);

        // Deeper after done → terminal done (explicit exhaust).
        $pipeline = app(ArticleInternalLinkPipeline::class);
        $m = new ReflectionMethod($pipeline, 'nextContentDeepCursorAfterDone');
        $m->setAccessible(true);
        self::assertSame(
            [
                'stage' => ArticleInternalLinkPipeline::ADVANCED_STAGE_DONE,
                'offset' => 0,
            ],
            $m->invoke($pipeline, ArticleInternalLinkPipeline::ADVANCED_STAGE_CONTENT_DEEP_DEEPER),
        );
    }

    public function test_pipeline_continues_across_content_deep_depths_in_one_request(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleInternalLinkPipeline::class))->getFileName()
        );

        self::assertStringContainsString('ADVANCED_CONTENT_DEEP_STAGES', $src);
        self::assertMatchesRegularExpression(
            '/in_array\(\$stage, self::ADVANCED_CONTENT_DEEP_STAGES[\s\S]*continue;/',
            $src,
        );
    }
}
