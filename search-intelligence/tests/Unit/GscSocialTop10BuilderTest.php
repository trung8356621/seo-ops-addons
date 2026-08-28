<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPageNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscSocialTop10Builder;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpUrlNormalizationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GscSocialTop10BuilderTest extends TestCase
{
    private function builder(): GscSocialTop10Builder
    {
        $normalizer = new GscPageNormalizationService(new SerpUrlNormalizationService);

        return new GscSocialTop10Builder($normalizer, null);
    }

    public function test_multiple_queries_same_page_collapse_to_one_article(): void
    {
        $page = 'https://example.test/may-balo-qua-tang';
        $result = $this->builder()->buildFromSignals(
            [
                [
                    'type' => 'near_page_one',
                    'query' => 'may balo quà tặng',
                    'metrics' => ['primary_page' => $page, 'impressions' => 79, 'position' => 11.8],
                ],
                [
                    'type' => 'high_impression_low_ctr',
                    'query' => 'xưởng may balo quà tặng',
                    'metrics' => ['primary_page' => $page, 'impressions' => 40, 'position' => 12.0],
                ],
                [
                    'type' => 'rising_query',
                    'query' => 'balo quà tặng',
                    'metrics' => ['primary_page' => $page, 'impressions' => 30],
                ],
            ],
            [
                $this->norm($page) => [
                    'article_id' => 101,
                    'title' => 'May balo quà tặng',
                    'url' => $page,
                    'path' => '/may-balo-qua-tang',
                ],
            ],
        );

        self::assertCount(1, $result['items']);
        self::assertSame(101, $result['items'][0]['article_id']);
        self::assertCount(3, $result['items'][0]['queries']);
        self::assertContains('Gần Top 10', $result['items'][0]['reason_tags']);
        self::assertContains('CTR thấp', $result['items'][0]['reason_tags']);
    }

    public function test_unmapped_and_no_covered_page_excluded(): void
    {
        $mapped = 'https://example.test/a';
        $unmapped = 'https://example.test/orphan';

        $result = $this->builder()->buildFromSignals(
            [
                [
                    'type' => 'near_page_one',
                    'query' => 'a',
                    'metrics' => ['primary_page' => $mapped, 'impressions' => 100, 'position' => 11],
                ],
                [
                    'type' => 'near_page_one',
                    'query' => 'orphan',
                    'metrics' => ['primary_page' => $unmapped, 'impressions' => 90, 'position' => 12],
                ],
                [
                    'type' => 'new_content_opportunity',
                    'query' => 'gap',
                    'metrics' => ['impressions' => 200],
                ],
            ],
            [
                $this->norm($mapped) => [
                    'article_id' => 1,
                    'title' => 'A',
                    'url' => $mapped,
                    'path' => '/a',
                ],
            ],
        );

        self::assertCount(1, $result['items']);
        self::assertSame(1, $result['unmapped_pages']);
        self::assertSame(1, $result['excluded_no_page']);
    }

    public function test_top_10_max_unique_articles(): void
    {
        $signals = [];
        $pageMap = [];
        for ($i = 1; $i <= 12; $i++) {
            $page = 'https://example.test/p'.$i;
            $signals[] = [
                'type' => 'near_page_one',
                'query' => 'q'.$i,
                'metrics' => ['primary_page' => $page, 'impressions' => 100 - $i, 'position' => 11.0],
            ];
            $pageMap[$this->norm($page)] = [
                'article_id' => $i,
                'title' => 'T'.$i,
                'url' => $page,
                'path' => '/p'.$i,
            ];
        }

        $result = $this->builder()->buildFromSignals($signals, $pageMap);
        self::assertCount(10, $result['items']);
        self::assertSame(range(1, 10), array_column($result['items'], 'article_id'));
    }

    public function test_preserves_mcp_signal_order_not_new_scoring(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(GscSocialTop10Builder::class))->getFileName()
        );
        self::assertStringNotContainsString('social_score', $source);
        self::assertStringNotContainsString('Prompt', $source);
        self::assertStringNotContainsString('OpenAI', $source);
        self::assertStringContainsString('new_content_opportunity', $source);
        self::assertStringContainsString('MAX_ITEMS = 10', $source);
    }

    public function test_opportunity_detection_attaches_primary_page(): void
    {
        $detector = new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscOpportunityDetectionService(
            new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPerformanceAggregationService,
            new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscExpectedCtrModel,
        );
        $detector->resetFingerprints();

        $opps = $detector->detect(
            [[
                'clicks' => 5,
                'impressions' => 200,
                'ctr' => 0.025,
                'position' => 12.0,
                'normalized_page' => 'https://example.test/may-balo',
                'page' => 'https://example.test/may-balo',
            ]],
            [],
            [
                'normalized_query' => 'may balo',
                'has_published_page' => true,
                'first_seen_date' => date('Y-m-d', strtotime('-90 days')),
            ],
        );

        self::assertNotEmpty($opps);
        foreach ($opps as $opp) {
            self::assertSame(
                'https://example.test/may-balo',
                $opp['evidence']['primary_page'] ?? null,
            );
        }
    }

    private function norm(string $url): string
    {
        return (new GscPageNormalizationService(new SerpUrlNormalizationService))
            ->normalize($url)['normalized_url'];
    }
}
