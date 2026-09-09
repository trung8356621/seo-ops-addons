<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkPriorityMerger;
use Omnichannel\Addons\Content\Services\ArticleEditorLinksPayloadService;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkPipeline;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkSuggestionService;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Staged Internal Link priority — source stage beats raw score.
 */
final class ArticleInternalLinkPriorityPipelineTest extends TestCase
{
    public function test_source_stage_beats_global_score(): void
    {
        $merger = new ArticleInternalLinkPriorityMerger;
        $merged = $merger->merge([
            ArticleInternalLinkPriorityMerger::STAGE_PRODUCT_CAT => [
                [
                    'text' => 'May Túi Vải Không Dệt',
                    'href' => 'https://example.com/product-cat',
                    'score' => 80,
                ],
            ],
            ArticleInternalLinkPriorityMerger::STAGE_TOPIC => [
                [
                    'text' => 'Túi Canvas',
                    'href' => 'https://example.com/topic-canvas',
                    'score' => 95,
                ],
            ],
            ArticleInternalLinkPriorityMerger::STAGE_KEYWORD_NON_TOPIC => [
                [
                    'text' => 'Túi vải bố',
                    'href' => 'https://example.com/keyword-bo',
                    'score' => 100,
                ],
            ],
            ArticleInternalLinkPriorityMerger::STAGE_GENERIC => [
                [
                    'text' => 'Nên chọn túi canvas hay túi vải không dệt',
                    'href' => 'https://example.com/generic-article',
                    'score' => 150,
                ],
            ],
        ]);

        self::assertCount(4, $merged);
        self::assertSame('product_cat', $merged[0]['source_stage']);
        self::assertSame(80, (int) $merged[0]['score']);
        self::assertSame('topic', $merged[1]['source_stage']);
        self::assertSame(95, (int) $merged[1]['score']);
        self::assertSame('keyword_non_topic', $merged[2]['source_stage']);
        self::assertSame(100, (int) $merged[2]['score']);
        self::assertSame('generic', $merged[3]['source_stage']);
        self::assertSame(150, (int) $merged[3]['score']);
    }

    public function test_same_url_prefers_higher_stage(): void
    {
        $merger = new ArticleInternalLinkPriorityMerger;
        $merged = $merger->merge([
            ArticleInternalLinkPriorityMerger::STAGE_PRODUCT_CAT => [
                [
                    'text' => 'Cat phrase',
                    'href' => 'https://example.com/same',
                    'score' => 70,
                ],
            ],
            ArticleInternalLinkPriorityMerger::STAGE_KEYWORD_NON_TOPIC => [
                [
                    'text' => 'Keyword phrase',
                    'href' => 'https://example.com/same/',
                    'score' => 99,
                ],
            ],
        ]);

        self::assertCount(1, $merged);
        self::assertSame('product_cat', $merged[0]['source_stage']);
        self::assertSame('Cat phrase', $merged[0]['text']);
    }

    public function test_already_linked_url_excluded(): void
    {
        $merger = new ArticleInternalLinkPriorityMerger;
        $merged = $merger->merge(
            [
                ArticleInternalLinkPriorityMerger::STAGE_TOPIC => [
                    [
                        'text' => 'Already',
                        'href' => 'https://example.com/linked',
                        'score' => 90,
                    ],
                ],
            ],
            alreadyLinkedNormalizedUrls: ['example.com/linked'],
        );

        self::assertSame([], $merged);
    }

    public function test_within_stage_sorts_by_score_desc(): void
    {
        $merger = new ArticleInternalLinkPriorityMerger;
        $merged = $merger->merge([
            ArticleInternalLinkPriorityMerger::STAGE_TOPIC => [
                ['text' => 'Low', 'href' => 'https://example.com/a', 'score' => 70],
                ['text' => 'High', 'href' => 'https://example.com/b', 'score' => 91],
            ],
        ]);

        self::assertSame('High', $merged[0]['text']);
        self::assertSame('Low', $merged[1]['text']);
    }

    public function test_payload_service_has_no_orphan_bucket(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleEditorLinksPayloadService::class))->getFileName()
        );
        self::assertStringNotContainsString('suggested_orphan_links', $src);
        self::assertStringNotContainsString('withOrphanSuggestions', $src);
        self::assertStringNotContainsString('ArticleInboundLinkGraphService', $src);
        self::assertStringContainsString('internal_link_catalog', $src);
    }

    public function test_pipeline_stage_order_contract(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleInternalLinkPipeline::class))->getFileName()
        );
        $productPos = strpos($src, 'Stage 1: FULL product_cat');
        $topicPos = strpos($src, 'STAGE_TOPIC');
        $nonTopicPos = strpos($src, 'STAGE_KEYWORD_NON_TOPIC');
        $genericPos = strpos($src, 'Stage 4 generic');

        self::assertNotFalse($productPos);
        self::assertNotFalse($topicPos);
        self::assertNotFalse($nonTopicPos);
        self::assertNotFalse($genericPos);
        self::assertLessThan($topicPos, $productPos);
        self::assertLessThan($genericPos, $nonTopicPos);
        self::assertStringNotContainsString('usort($internalSuggestions', $src);
    }

    public function test_find_more_uses_same_pipeline_not_blue_only(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleInternalLinkSuggestionService::class))->getFileName()
        );
        self::assertStringContainsString('suggestMoreSamePipeline', $src);
        self::assertStringContainsString('collectCandidates', $src);
        self::assertStringNotContainsString('forceRun: true', $src);
    }

    public function test_sidebar_has_no_orphan_pages_section(): void
    {
        $sidebar = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/ArticleLinksSidebar.jsx'
        );
        self::assertStringNotContainsString('links_orphan_pages_title', $sidebar);
        self::assertStringNotContainsString('suggestedOrphan', $sidebar);
        self::assertStringNotContainsString('orphanCatalogRef', $sidebar);
        self::assertStringContainsString('links_find_more_suggestions', $sidebar);
        // Full pipeline — no auto blue second pass on first generate.
        self::assertStringNotContainsString('usableCount < 3', $sidebar);
    }

    public function test_site_mcp_root_only_still_present(): void
    {
        $method = new ReflectionMethod(SiteMcpGenerator::class, 'rootProductCategories');
        $file = (string) $method->getFileName();
        $lines = file($file);
        self::assertIsArray($lines);
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        self::assertStringContainsString('parent_term_id', $body);
        self::assertStringContainsString('!== 0', $body);
    }
}
