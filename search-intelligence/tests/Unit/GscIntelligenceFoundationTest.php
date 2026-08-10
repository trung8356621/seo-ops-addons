<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscBrandQueryType;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscContentAction;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPageMappingMethod;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Contracts\GscIntelligenceProviderRegistry;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Data\GscSearchAnalyticsRequest;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscBrandQueryClassifier;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscContentActionRecommendationService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscContentProjectPreviewBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscDailyMetricPersistService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscExpectedCtrModel;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscFactHashService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscImportPreviewService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPageArticleMapper;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPageNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPerformanceAggregationService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscProviderResolver;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscQueryKeywordMapper;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscQueryNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscSyncDateRangeService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Providers\FakeLocalGscProvider;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Providers\ManualImportGscProvider;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscDailyMetric;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpUrlNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordNormalizationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GscIntelligenceFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        GscDailyMetricPersistService::resetFacts();
    }

    public function test_query_normalization_keeps_vietnamese_diacritics(): void
    {
        $service = new GscQueryNormalizationService(new KeywordNormalizationService);
        $normalized = $service->normalize('  Dá»‹ch Vá»¥ SEO  ');

        self::assertSame('dá»‹ch vá»¥ seo', $normalized);
    }

    public function test_import_preview_recalculates_ctr_and_rejects_clicks_over_impressions(): void
    {
        $service = $this->importPreviewService();
        $csv = <<<'CSV'
date,query,page,country,device,search_appearance,clicks,impressions,ctr,position
2026-07-01,dá»‹ch vá»¥ seo,https://example.test/dich-vu-seo,vnm,desktop,,10,100,99,8.5
2026-07-01,seo lÃ  gÃ¬,https://example.test/seo-la-gi,vnm,mobile,,150,100,1.5,4
CSV;

        $preview = $service->preview($csv);
        self::assertCount(1, $preview->validRows);
        self::assertSame(0.1, $preview->validRows[0]['ctr']);
        self::assertCount(1, $preview->invalidRows);
        self::assertSame('clicks_exceed_impressions', $preview->invalidRows[0]['reason']);
    }

    public function test_daily_metric_upsert_replaces_not_sums(): void
    {
        $hash = new GscFactHashService;
        $persist = new GscDailyMetricPersistService($hash);

        $row = [
            'date' => '2026-07-01',
            'query' => 'dá»‹ch vá»¥ seo',
            'normalized_query' => 'dá»‹ch vá»¥ seo',
            'page' => 'https://example.test/a',
            'normalized_page' => 'https://example.test/a',
            'country' => 'vnm',
            'device' => 'desktop',
            'search_appearance' => 'none',
            'clicks' => 5,
            'impressions' => 50,
            'ctr' => 0.1,
            'position' => 8.0,
        ];

        $persist->upsert('prop_1', $row);
        $row['clicks'] = 20;
        $row['impressions'] = 80;
        $row['ctr'] = 0.25;
        $persist->upsert('prop_1', $row);

        $stored = $persist->allFacts()[0];
        self::assertSame(20, $stored['clicks']);
        self::assertSame(80, $stored['impressions']);
        self::assertSame(0.25, $stored['ctr']);
    }

    public function test_page_mapper_prefers_manual_and_flags_ambiguous(): void
    {
        $mapper = new GscPageArticleMapper(new GscPageNormalizationService(new SerpUrlNormalizationService));
        $candidates = [
            ['site_id' => '1', 'article_ref' => 'art_a', 'method' => 'manual', 'manual' => true, 'normalized_url' => 'https://example.test/post'],
            ['site_id' => '1', 'article_ref' => 'art_b', 'method' => 'exact_canonical', 'canonical_url' => 'https://example.test/post'],
        ];

        $mapped = $mapper->map('https://example.test/post', '1', $candidates);
        self::assertSame('art_a', $mapped['article_ref']);
        self::assertSame(GscPageMappingMethod::Manual, $mapped['method']);

        $ambiguousCandidates = [
            ['site_id' => '1', 'article_ref' => 'art_a', 'method' => 'exact_canonical', 'canonical_url' => 'https://example.test/post'],
            ['site_id' => '1', 'article_ref' => 'art_b', 'method' => 'exact_canonical', 'canonical_url' => 'https://example.test/post'],
        ];
        $ambiguous = $mapper->map('https://example.test/post', '1', $ambiguousCandidates);
        self::assertSame(GscPageArticleMapper::ERROR_AMBIGUOUS, $ambiguous['error_code']);
    }

    public function test_query_keyword_mapper_blocks_near_duplicate_with_different_intent(): void
    {
        $mapper = new GscQueryKeywordMapper(new GscQueryNormalizationService(new KeywordNormalizationService));
        $candidates = [
            ['site_id' => '1', 'keyword_ref' => 'kw_1', 'normalized' => 'seo lÃ  gÃ¬'],
        ];

        $mapped = $mapper->map('dá»‹ch vá»¥ seo', '1', $candidates);
        self::assertNull($mapped['keyword_ref']);
        self::assertSame('unmapped', $mapped['match_type']);
    }

    public function test_performance_aggregation_weighted_position_and_null_when_zero_impressions(): void
    {
        $agg = new GscPerformanceAggregationService;
        $result = $agg->aggregate([
            ['clicks' => 2, 'impressions' => 10, 'position' => 4.0],
            ['clicks' => 3, 'impressions' => 30, 'position' => 8.0],
        ]);

        self::assertSame(5, $result['clicks']);
        self::assertSame(40, $result['impressions']);
        self::assertSame(0.125, $result['ctr']);
        self::assertSame(7.0, $result['position']);

        $empty = $agg->aggregate([]);
        self::assertNull($empty['ctr']);
        self::assertNull($empty['position']);
    }

    public function test_provider_resolver_fail_closed_codes(): void
    {
        $registry = new GscIntelligenceProviderRegistry;
        $registry->register(new ManualImportGscProvider($this->importPreviewService()));
        $registry->register(new FakeLocalGscProvider(new GscFactHashService));

        $resolver = new GscProviderResolver($registry);
        $request = new GscSearchAnalyticsRequest(
            tenantRef: null,
            siteRef: '1',
            propertyRef: 'prop_1',
            startDate: '2026-07-01',
            endDate: '2026-07-07',
            providerKey: '',
        );

        self::assertSame('gsc_provider.not_configured', $resolver->resolve($request)['error_code']);

        $request = new GscSearchAnalyticsRequest(
            tenantRef: null,
            siteRef: '1',
            propertyRef: 'prop_1',
            startDate: '2026-07-01',
            endDate: '2026-07-07',
            providerKey: 'missing',
        );
        self::assertSame('gsc_provider.not_registered', $resolver->resolve($request)['error_code']);
    }

    public function test_rewrite_action_requires_reviewed_evidence(): void
    {
        $service = new GscContentActionRecommendationService;
        $metrics = ['clicks' => 10, 'impressions' => 200, 'ctr' => 0.05, 'position' => 12.0];
        $context = [
            'article_ref' => 'art_1',
            'opportunities' => [['type' => 'content_decay']],
        ];

        $needsReview = $service->recommend($metrics, $context);
        self::assertSame(GscContentAction::NeedsReview, $needsReview['action']);

        $context['reviewed_evidence'] = true;
        $rewrite = $service->recommend($metrics, $context);
        self::assertSame(GscContentAction::Rewrite, $rewrite['action']);
    }

    public function test_preview_builder_uses_improve_description_not_gallery_description(): void
    {
        $builder = new GscContentProjectPreviewBuilder;
        $item = $builder->build(
            ['action' => GscContentAction::Improve, 'reason_codes' => ['near_page_one'], 'article_ref' => 'art_1'],
            ['clicks' => 20, 'impressions' => 500, 'ctr' => 0.04, 'position' => 9.2],
            ['display_query' => 'dá»‹ch vá»¥ seo', 'opportunities' => [['type' => 'near_page_one']]],
        );

        self::assertArrayHasKey('improve_description', $item);
        self::assertStringContainsString('dá»‹ch vá»¥ seo', $item['improve_description']);
        self::assertArrayNotHasKey('gallery_description', $item);
    }

    public function test_sync_date_range_uses_config_chunking(): void
    {
        $service = new GscSyncDateRangeService;
        $ranges = $service->buildFullRanges('2026-07-01', '2026-07-30');

        self::assertNotEmpty($ranges);
        self::assertSame('2026-07-01', $ranges[0]['start']);
    }

    public function test_brand_classifier(): void
    {
        $classifier = new GscBrandQueryClassifier(new GscQueryNormalizationService(new KeywordNormalizationService));
        self::assertSame(GscBrandQueryType::Unknown, $classifier->classify('dá»‹ch vá»¥ seo'));
        self::assertSame(GscBrandQueryType::Brand, $classifier->classify('omi seo tool', ['omi']));
    }

    public function test_gsc_public_ref_roundtrip_and_rejects_numeric(): void
    {
        $pairs = [
            ['gscProperty', 'decodeGscProperty', 'resolveGscPropertyIdStrict', 'gscp_', 1],
            ['gscSyncRun', 'decodeGscSyncRun', 'resolveGscSyncRunIdStrict', 'gscs_', 2],
            ['gscQueryMapping', 'decodeGscQueryMapping', 'resolveGscQueryMappingIdStrict', 'gscq_', 3],
            ['gscPageMapping', 'decodeGscPageMapping', 'resolveGscPageMappingIdStrict', 'gscm_', 4],
            ['gscPerformanceAggregate', 'decodeGscPerformanceAggregate', 'resolveGscPerformanceAggregateIdStrict', 'gsca_', 5],
            ['gscOpportunity', 'decodeGscOpportunity', 'resolveGscOpportunityIdStrict', 'gsco_', 6],
        ];

        foreach ($pairs as [$encode, $decode, $resolve, $prefix, $id]) {
            $ref = KeywordIntelligencePublicRef::{$encode}($id);
            self::assertStringStartsWith($prefix, $ref);
            self::assertSame($id, KeywordIntelligencePublicRef::{$decode}($ref));
            self::assertSame($id, KeywordIntelligencePublicRef::{$resolve}($ref));
        }

        $this->expectException(\InvalidArgumentException::class);
        KeywordIntelligencePublicRef::resolveGscPropertyIdStrict('1');
    }

    public function test_data_hash_is_stable_and_unique_per_dimensions(): void
    {
        $hash = new GscFactHashService;
        $a = $hash->dataHash('prop_1', '2026-07-01', 'dá»‹ch vá»¥ seo', 'https://example.test/a', 'vnm', 'desktop', 'none');
        $b = $hash->dataHash('prop_1', '2026-07-01', 'dá»‹ch vá»¥ seo', 'https://example.test/a', 'vnm', 'desktop', 'none');
        $c = $hash->dataHash('prop_1', '2026-07-01', 'seo lÃ  gÃ¬', 'https://example.test/a', 'vnm', 'desktop', 'none');

        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
        self::assertSame(64, strlen($a));
    }

    public function test_daily_metric_model_casts_ctr_and_position_as_decimals(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(SeoGscDailyMetric::class))->getFileName());
        self::assertStringContainsString("'ctr' => 'decimal:6'", $source);
        self::assertStringContainsString("'position' => 'decimal:3'", $source);
    }

    public function test_gsc_handlers_do_not_import_google_client(): void
    {
        $dir = ProjectRoot::addonsPath().'/search-intelligence/src/Services/GscIntelligence/Application/Handlers';
        if (! is_dir($dir)) {
            self::markTestSkipped('GSC handlers directory missing');

            return;
        }

        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('Google\\Client', $source, basename($file));
            self::assertStringNotContainsString('Google_Service', $source, basename($file));
        }
    }

    private function importPreviewService(): GscImportPreviewService
    {
        $queryNormalizer = new GscQueryNormalizationService(new KeywordNormalizationService);
        $pageNormalizer = new GscPageNormalizationService(new SerpUrlNormalizationService);

        return new GscImportPreviewService($queryNormalizer, $pageNormalizer, new GscFactHashService);
    }
}
