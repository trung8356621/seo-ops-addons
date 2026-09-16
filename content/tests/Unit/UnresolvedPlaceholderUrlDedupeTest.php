<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkPipeline;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkPriorityMerger;
use Omnichannel\Addons\Seo\Support\SeoSuggestionUrlNormalizer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Regression: unresolved href="#" must not collide in PriorityMerger URL dedupe.
 */
final class UnresolvedPlaceholderUrlDedupeTest extends TestCase
{
    public function test_url_dedupe_key_for_hash_placeholder_is_empty_after_fix(): void
    {
        $merger = new ArticleInternalLinkPriorityMerger;
        $key = $this->invokeUrlDedupeKey($merger, '#');

        // Failing before fix: '#' ; after fix: ''.
        self::assertSame('', $key);
    }

    public function test_multiple_unresolved_placeholders_survive_merge(): void
    {
        $merger = new ArticleInternalLinkPriorityMerger;
        $merged = $merger->merge([
            ArticleInternalLinkPriorityMerger::STAGE_GENERIC => [
                [
                    'text' => 'anchor one',
                    'href' => '#',
                    'destination_resolved' => false,
                    'score' => 80,
                ],
                [
                    'text' => 'anchor two',
                    'href' => '#',
                    'destination_resolved' => false,
                    'score' => 70,
                ],
                [
                    'text' => 'anchor three',
                    'href' => '#',
                    'destination_resolved' => false,
                    'score' => 60,
                ],
            ],
        ]);

        self::assertCount(3, $merged);
        self::assertSame(
            ['anchor one', 'anchor two', 'anchor three'],
            array_map(static fn (array $row): string => (string) $row['text'], $merged),
        );
        foreach ($merged as $row) {
            self::assertSame('#', $row['href']);
            self::assertFalse((bool) ($row['destination_resolved'] ?? true));
        }
    }

    public function test_same_unresolved_anchor_still_dedupes_by_label(): void
    {
        $merger = new ArticleInternalLinkPriorityMerger;
        $merged = $merger->merge([
            ArticleInternalLinkPriorityMerger::STAGE_GENERIC => [
                [
                    'text' => 'túi vải bố',
                    'href' => '#',
                    'destination_resolved' => false,
                    'score' => 80,
                ],
                [
                    'text' => 'Túi Vải Bố',
                    'href' => '#',
                    'destination_resolved' => false,
                    'score' => 70,
                ],
            ],
        ]);

        self::assertCount(1, $merged);
        self::assertSame('túi vải bố', $merged[0]['text']);
    }

    public function test_real_urls_still_dedupe(): void
    {
        $merger = new ArticleInternalLinkPriorityMerger;
        $merged = $merger->merge([
            ArticleInternalLinkPriorityMerger::STAGE_GENERIC => [
                [
                    'text' => 'anchor A',
                    'href' => 'https://site.test/foo',
                    'destination_resolved' => true,
                    'score' => 90,
                ],
                [
                    'text' => 'anchor B',
                    'href' => 'https://site.test/foo/',
                    'destination_resolved' => true,
                    'score' => 80,
                ],
            ],
        ]);

        self::assertCount(1, $merged);
        self::assertSame('anchor A', $merged[0]['text']);
    }

    public function test_mixed_real_and_unresolved_unique_anchors(): void
    {
        $merger = new ArticleInternalLinkPriorityMerger;
        $merged = $merger->merge([
            ArticleInternalLinkPriorityMerger::STAGE_TOPIC => [
                [
                    'text' => 'real A',
                    'href' => 'https://site.test/a',
                    'destination_resolved' => true,
                    'score' => 90,
                ],
            ],
            ArticleInternalLinkPriorityMerger::STAGE_GENERIC => [
                [
                    'text' => 'unresolved B',
                    'href' => '#',
                    'destination_resolved' => false,
                    'score' => 80,
                ],
                [
                    'text' => 'unresolved C',
                    'href' => '#',
                    'destination_resolved' => false,
                    'score' => 70,
                ],
                [
                    'text' => 'real D',
                    'href' => 'https://site.test/d',
                    'destination_resolved' => true,
                    'score' => 60,
                ],
            ],
        ]);

        self::assertCount(4, $merged);
        self::assertSame(
            ['real A', 'unresolved B', 'unresolved C', 'real D'],
            array_map(static fn (array $row): string => (string) $row['text'], $merged),
        );
    }

    public function test_already_linked_real_url_still_excluded(): void
    {
        $merger = new ArticleInternalLinkPriorityMerger;
        $merged = $merger->merge(
            [
                ArticleInternalLinkPriorityMerger::STAGE_GENERIC => [
                    [
                        'text' => 'Already',
                        'href' => 'https://site.test/linked',
                        'destination_resolved' => true,
                        'score' => 90,
                    ],
                    [
                        'text' => 'fresh unresolved',
                        'href' => '#',
                        'destination_resolved' => false,
                        'score' => 80,
                    ],
                ],
            ],
            alreadyLinkedNormalizedUrls: ['site.test/linked'],
        );

        self::assertCount(1, $merged);
        self::assertSame('fresh unresolved', $merged[0]['text']);
    }

    public function test_hash_fragment_does_not_collide_in_url_dedupe(): void
    {
        $merger = new ArticleInternalLinkPriorityMerger;
        self::assertSame('', $this->invokeUrlDedupeKey($merger, '#section'));
        self::assertSame('', $this->invokeUrlDedupeKey($merger, 'javascript:void(0)'));

        $merged = $merger->merge([
            ArticleInternalLinkPriorityMerger::STAGE_GENERIC => [
                [
                    'text' => 'frag one',
                    'href' => '#section-a',
                    'destination_resolved' => false,
                    'score' => 80,
                ],
                [
                    'text' => 'frag two',
                    'href' => '#section-b',
                    'destination_resolved' => false,
                    'score' => 70,
                ],
            ],
        ]);

        self::assertCount(2, $merged);
    }

    public function test_stage_priority_unchanged_with_placeholders(): void
    {
        $merger = new ArticleInternalLinkPriorityMerger;
        $merged = $merger->merge([
            ArticleInternalLinkPriorityMerger::STAGE_PRODUCT_CAT => [
                [
                    'text' => 'cat',
                    'href' => 'https://site.test/cat',
                    'destination_resolved' => true,
                    'score' => 50,
                ],
            ],
            ArticleInternalLinkPriorityMerger::STAGE_TOPIC => [
                [
                    'text' => 'topic',
                    'href' => '#',
                    'destination_resolved' => false,
                    'score' => 99,
                ],
            ],
            ArticleInternalLinkPriorityMerger::STAGE_KEYWORD_NON_TOPIC => [
                [
                    'text' => 'kw',
                    'href' => '#',
                    'destination_resolved' => false,
                    'score' => 98,
                ],
            ],
            ArticleInternalLinkPriorityMerger::STAGE_GENERIC => [
                [
                    'text' => 'generic',
                    'href' => '#',
                    'destination_resolved' => false,
                    'score' => 150,
                ],
            ],
        ]);

        self::assertCount(4, $merged);
        self::assertSame('product_cat', $merged[0]['source_stage']);
        self::assertSame('topic', $merged[1]['source_stage']);
        self::assertSame('keyword_non_topic', $merged[2]['source_stage']);
        self::assertSame('generic', $merged[3]['source_stage']);
    }

    /**
     * Pipeline-shaped fixture: 3 unresolved generic suggestions (as emit after
     * resolveBestForAnchors) must all survive the same merge call collect() uses.
     */
    public function test_pipeline_shaped_unresolved_generic_batch_survives_final_merge(): void
    {
        self::assertTrue(SeoSuggestionUrlNormalizer::isPlaceholder('#'));

        $pipelineSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleInternalLinkPipeline::class))->getFileName(),
        );
        self::assertStringContainsString('priorityMerger->merge', $pipelineSrc);
        self::assertStringContainsString('UnresolvedInternalLinkSuggestion::make', $pipelineSrc);

        // Exact payload shape emitted for unresolved generic stage rows.
        $genericBatch = [
            [
                'text' => 'phrase alpha seo',
                'keyword_id' => 11,
                'href' => '#',
                'target_url' => null,
                'target_article_id' => 101,
                'destination_resolved' => false,
                'can_insert' => true,
                'is_suggestion' => true,
                'score' => 88,
                'match_reason' => 'title_match',
                'source' => ArticleInternalLinkPriorityMerger::STAGE_GENERIC,
                'candidate_source' => ArticleInternalLinkPriorityMerger::STAGE_GENERIC,
            ],
            [
                'text' => 'phrase beta seo',
                'keyword_id' => 12,
                'href' => '#',
                'target_url' => null,
                'target_article_id' => 102,
                'destination_resolved' => false,
                'can_insert' => true,
                'is_suggestion' => true,
                'score' => 77,
                'match_reason' => 'title_match',
                'source' => ArticleInternalLinkPriorityMerger::STAGE_GENERIC,
                'candidate_source' => ArticleInternalLinkPriorityMerger::STAGE_GENERIC,
            ],
            [
                'text' => 'phrase gamma seo',
                'keyword_id' => 13,
                'href' => '#',
                'target_url' => null,
                'target_article_id' => 103,
                'destination_resolved' => false,
                'can_insert' => true,
                'is_suggestion' => true,
                'score' => 66,
                'match_reason' => 'keyword_match',
                'source' => ArticleInternalLinkPriorityMerger::STAGE_GENERIC,
                'candidate_source' => ArticleInternalLinkPriorityMerger::STAGE_GENERIC,
            ],
        ];

        $merger = new ArticleInternalLinkPriorityMerger;
        $merged = $merger->merge(
            [
                ArticleInternalLinkPriorityMerger::STAGE_PRODUCT_CAT => [],
                ArticleInternalLinkPriorityMerger::STAGE_TOPIC => [],
                ArticleInternalLinkPriorityMerger::STAGE_KEYWORD_NON_TOPIC => [],
                ArticleInternalLinkPriorityMerger::STAGE_GENERIC => $genericBatch,
            ],
            alreadyLinkedNormalizedUrls: [],
            alreadyLinkedLabels: [],
        );

        self::assertCount(3, $merged);
        foreach ($merged as $row) {
            self::assertSame('#', $row['href']);
            self::assertFalse((bool) $row['destination_resolved']);
            self::assertTrue((bool) $row['can_insert']);
            self::assertSame('generic', $row['source_stage']);
        }
    }

    private function invokeUrlDedupeKey(ArticleInternalLinkPriorityMerger $merger, string $url): string
    {
        $method = new ReflectionMethod(ArticleInternalLinkPriorityMerger::class, 'urlDedupeKey');
        $method->setAccessible(true);

        return (string) $method->invoke($merger, $url);
    }
}
