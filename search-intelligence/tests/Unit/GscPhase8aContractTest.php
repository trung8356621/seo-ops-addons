<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscOpportunityType;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscExpectedCtrModel;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscIntelligencePolicy;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscOpportunityDetectionService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPerformanceAggregationService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPlanningSignalNormalizer;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPositionSemantics;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscQueryCannibalizationDetector;
use Omnichannel\Addons\SearchIntelligence\Support\GscIntelligence\GscMcpContextBuilder;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexHealthRecorder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Phase 8A — GSC Search Performance Intelligence contracts.
 */
final class GscPhase8aContractTest extends TestCase
{
    public function test_mcp_source_key_includes_gsc(): void
    {
        self::assertSame('gsc', McpSourceKey::Gsc->value);
        self::assertSame('gsc.mcp.v1', McpSourceKey::Gsc->schema());
    }

    public function test_position_semantics_lower_is_better(): void
    {
        $cmp = GscPositionSemantics::compare(11.0, 6.0);
        self::assertTrue($cmp['worsened']);
        self::assertFalse($cmp['improved']);
        self::assertSame(5.0, $cmp['delta']);
        self::assertTrue(GscPositionSemantics::worsenedByAtLeast(11.0, 6.0, 2.0));
        self::assertTrue(GscPositionSemantics::improvedByAtLeast(4.0, 9.0, 2.0));
    }

    public function test_policy_caps_are_centralized(): void
    {
        self::assertSame(30, GscIntelligencePolicy::MAX_TOP_QUERIES);
        self::assertSame(20, GscIntelligencePolicy::MAX_FALLING);
        self::assertSame(20, GscIntelligencePolicy::MAX_RISING);
        self::assertSame(30, GscIntelligencePolicy::MAX_PLANNING_SIGNALS);
        self::assertGreaterThanOrEqual(50, GscIntelligencePolicy::minImpressionsForComparison());
    }

    public function test_falling_requires_meaningful_baseline_impressions(): void
    {
        $detector = new GscOpportunityDetectionService(
            new GscPerformanceAggregationService,
            new GscExpectedCtrModel,
        );
        $detector->resetFingerprints();

        $tiny = $detector->detect(
            [['clicks' => 1, 'impressions' => 1, 'ctr' => 1.0, 'position' => 12.0]],
            [['clicks' => 2, 'impressions' => 2, 'ctr' => 1.0, 'position' => 6.0]],
            ['normalized_query' => 'noise query', 'first_seen_date' => date('Y-m-d', strtotime('-90 days'))],
        );
        $types = array_column($tiny, 'type');
        self::assertNotContains(GscOpportunityType::PositionDecline->value, $types);
        self::assertNotContains(GscOpportunityType::ClickDecline->value, $types);
        self::assertNotContains(GscOpportunityType::ContentDecay->value, $types);
    }

    public function test_falling_and_rising_signals_with_evidence(): void
    {
        $detector = new GscOpportunityDetectionService(
            new GscPerformanceAggregationService,
            new GscExpectedCtrModel,
        );
        $detector->resetFingerprints();

        $falling = $detector->detect(
            [['clicks' => 50, 'impressions' => 500, 'ctr' => 0.1, 'position' => 12.0]],
            [['clicks' => 200, 'impressions' => 800, 'ctr' => 0.25, 'position' => 5.0]],
            [
                'normalized_query' => 'balo học sinh',
                'first_seen_date' => date('Y-m-d', strtotime('-90 days')),
                'has_published_page' => true,
            ],
        );
        $fallingTypes = array_column($falling, 'type');
        self::assertContains(GscOpportunityType::ContentDecay->value, $fallingTypes);
        self::assertContains(GscOpportunityType::PositionDecline->value, $fallingTypes);

        $detector->resetFingerprints();
        $rising = $detector->detect(
            [['clicks' => 80, 'impressions' => 600, 'ctr' => 0.13, 'position' => 8.0]],
            [['clicks' => 40, 'impressions' => 200, 'ctr' => 0.2, 'position' => 10.0]],
            [
                'normalized_query' => 'balo học sinh rising',
                'first_seen_date' => date('Y-m-d', strtotime('-90 days')),
            ],
        );
        self::assertContains(GscOpportunityType::ImpressionGrowth->value, array_column($rising, 'type'));
    }

    public function test_ctr_opportunity_ignores_tiny_sample(): void
    {
        $detector = new GscOpportunityDetectionService(
            new GscPerformanceAggregationService,
            new GscExpectedCtrModel,
        );
        $detector->resetFingerprints();
        $opps = $detector->detect(
            [['clicks' => 0, 'impressions' => 5, 'ctr' => 0.0, 'position' => 3.0]],
            [],
            ['normalized_query' => 'tiny', 'first_seen_date' => date('Y-m-d', strtotime('-90 days'))],
        );
        self::assertNotContains(GscOpportunityType::HighImpressionLowCtr->value, array_column($opps, 'type'));
    }

    public function test_possible_cannibalization_is_possible_only(): void
    {
        $detector = new GscQueryCannibalizationDetector;
        $issues = $detector->detect('balo học sinh', [
            ['normalized_query' => 'balo học sinh', 'normalized_page' => 'https://example.test/a', 'impressions' => 40, 'clicks' => 5],
            ['normalized_query' => 'balo học sinh', 'normalized_page' => 'https://example.test/b', 'impressions' => 35, 'clicks' => 3],
        ]);
        self::assertNotEmpty($issues);

        $signals = (new GscPlanningSignalNormalizer)->normalize([], $issues);
        self::assertSame('possible_cannibalization', $signals[0]['type']);
        self::assertSame('improvement_signal', $signals[0]['lane']);
        self::assertSame(GscPlanningSignalNormalizer::EVIDENCE_TYPE, $signals[0]['evidence_type']);
    }

    public function test_planning_lane_improvement_vs_new_content(): void
    {
        $normalizer = new GscPlanningSignalNormalizer;
        $signals = $normalizer->normalize([
            [
                'type' => GscOpportunityType::ContentDecay->value,
                'normalized_query' => 'old page',
                'has_published_page' => true,
                'evidence' => ['drop_pct' => 0.4, 'impressions' => 200],
            ],
            [
                'type' => GscOpportunityType::UnmappedQuery->value,
                'normalized_query' => 'gap query',
                'has_published_page' => false,
                'evidence' => ['impressions' => 300],
            ],
        ]);

        $byQuery = [];
        foreach ($signals as $s) {
            $byQuery[$s['query']] = $s;
        }
        self::assertSame('improvement_signal', $byQuery['old page']['lane']);
        self::assertSame('new_content_signal', $byQuery['gap query']['lane']);
    }

    public function test_mcp_builder_from_prepared_monthly_summary(): void
    {
        $builder = new GscMcpContextBuilder(
            new GscPerformanceAggregationService,
            new GscOpportunityDetectionService(new GscPerformanceAggregationService, new GscExpectedCtrModel),
            new GscQueryCannibalizationDetector,
            new GscPlanningSignalNormalizer,
        );

        $current = [
            ['date' => '2026-08-01', 'normalized_query' => 'query a', 'query' => 'query a', 'normalized_page' => 'https://ex.test/a', 'page' => 'https://ex.test/a', 'clicks' => 10, 'impressions' => 200, 'ctr' => 0.05, 'position' => 12.0],
            ['date' => '2026-08-02', 'normalized_query' => 'query b', 'query' => 'query b', 'normalized_page' => 'https://ex.test/b', 'page' => 'https://ex.test/b', 'clicks' => 5, 'impressions' => 150, 'ctr' => 0.03, 'position' => 8.0],
        ];
        $previous = [
            ['date' => '2026-07-01', 'normalized_query' => 'query a', 'query' => 'query a', 'normalized_page' => 'https://ex.test/a', 'page' => 'https://ex.test/a', 'clicks' => 40, 'impressions' => 400, 'ctr' => 0.1, 'position' => 5.0],
            ['date' => '2026-07-02', 'normalized_query' => 'query b', 'query' => 'query b', 'normalized_page' => 'https://ex.test/b', 'page' => 'https://ex.test/b', 'clicks' => 4, 'impressions' => 100, 'ctr' => 0.04, 'position' => 9.0],
        ];

        $payload = $builder->fromPrepared(
            $current,
            $previous,
            'example.test',
            '2026-08',
            '2026-07',
            'gscp_test',
            'sc-domain:example.test',
            false,
            '2026-08-20T00:00:00+00:00',
        );

        self::assertSame(15, $payload['metrics']['clicks']);
        self::assertSame(350, $payload['metrics']['impressions']);
        self::assertArrayHasKey('planning_signals', $payload['context']);
        self::assertStringContainsString('not global search volume', (string) $payload['context']['note']);
        self::assertSame('2026-08', $payload['summary']['period']['current']);
        self::assertSame('2026-07', $payload['summary']['period']['previous']);
    }

    public function test_phase8a_does_not_call_url_inspection_or_mutate_index_health(): void
    {
        $files = [
            (new ReflectionClass(GscMcpContextBuilder::class))->getFileName(),
            (new ReflectionClass(GscPlanningSignalNormalizer::class))->getFileName(),
            (new ReflectionClass(\Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources\GscMonthlyMcpSource::class))->getFileName(),
        ];
        foreach ($files as $file) {
            $src = (string) file_get_contents((string) $file);
            self::assertStringNotContainsString('UrlInspection', $src);
            self::assertStringNotContainsString('urlInspection', $src);
            self::assertStringNotContainsString('seo_article_index_health', $src);
            self::assertStringNotContainsString('ArticleIndexHealthRecorder', $src);
        }

        $ingestSrc = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\GscKeywordIntelligenceIngestionService::class))->getFileName(),
        );
        self::assertStringNotContainsString('UrlInspection', $ingestSrc);
        self::assertStringNotContainsString('seo_article_index_health', $ingestSrc);
        self::assertStringNotContainsString("forceFill(['focus_keyword'", $ingestSrc);
        self::assertStringNotContainsString('->focus_keyword', $ingestSrc);
        self::assertStringNotContainsString('SeoLinkMap::', $ingestSrc);

        $recorderSrc = (string) file_get_contents((string) (new ReflectionClass(ArticleIndexHealthRecorder::class))->getFileName());
        self::assertStringNotContainsString('GscMcpContextBuilder', $recorderSrc);
        self::assertStringNotContainsString('GscDailyMetricPersistService', $recorderSrc);
    }

    public function test_ki_ingestion_source_does_not_reference_focus_overwrite(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\GscKeywordIntelligenceIngestionService::class))->getFileName(),
        );
        self::assertStringContainsString('SEARCH_CONSOLE', $src);
        self::assertStringContainsString('never write article.focus_keyword', $src);
        self::assertStringNotContainsString('PromptRunner', $src);
        self::assertStringNotContainsString('SeoLinkMap::', $src);
    }
}
