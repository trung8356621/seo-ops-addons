<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscSyncStage;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Contracts\GscIntelligenceProviderRegistry;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Data\GscSearchAnalyticsRequest;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscDailyMetricPersistService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscExpectedCtrModel;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscFactHashService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscImportPreviewService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscOpportunityDetectionService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPageArticleMapper;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPageNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPerformanceAggregationService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscProviderResolver;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscQueryKeywordMapper;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscQueryNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscSyncDateRangeService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscSyncLockService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscSyncOperationService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Providers\FakeLocalGscProvider;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Providers\ManualImportGscProvider;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordNormalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpUrlNormalizationService;
use PHPUnit\Framework\TestCase;

final class GscSyncOperationTest extends TestCase
{
    private GscSyncOperationService $sync;

    protected function setUp(): void
    {
        parent::setUp();
        GscDailyMetricPersistService::resetFacts();
        GscSyncOperationService::resetOperations();

        $queryNormalizer = new GscQueryNormalizationService(new KeywordNormalizationService);
        $pageNormalizer = new GscPageNormalizationService(new SerpUrlNormalizationService);
        $importPreview = new GscImportPreviewService($queryNormalizer, $pageNormalizer, new GscFactHashService);

        $registry = new GscIntelligenceProviderRegistry;
        $registry->register(new ManualImportGscProvider($importPreview));
        $registry->register(new FakeLocalGscProvider(new GscFactHashService));

        $lock = new class extends GscSyncLockService {
            public function __construct()
            {
            }

            public function withSyncLock(string $propertyRef, callable $callback, int $waitSeconds = 0): mixed
            {
                return $callback('test-owner');
            }
        };

        $this->sync = new GscSyncOperationService(
            $lock,
            new GscProviderResolver($registry),
            new GscDailyMetricPersistService(new GscFactHashService),
            new GscPageArticleMapper($pageNormalizer),
            new GscQueryKeywordMapper($queryNormalizer),
            new GscPerformanceAggregationService,
            new GscOpportunityDetectionService(new GscPerformanceAggregationService, new GscExpectedCtrModel),
        );
    }

    protected function tearDown(): void
    {
        GscSyncOperationService::resetOperations();
        parent::tearDown();
    }

    public function test_sync_lock_key_format(): void
    {
        $lock = new GscSyncLockService(new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock);
        self::assertSame('gsc-sync:prop_abc', $lock->syncKey('prop_abc'));
    }

    public function test_data_delay_default_from_config_path(): void
    {
        $service = new GscSyncDateRangeService;
        $end = $service->latestAvailableEnd(new \DateTimeImmutable('2026-07-10'));
        self::assertSame('2026-07-07', $end['end']);
    }

    public function test_sync_records_stages_through_completion(): void
    {
        $request = new GscSearchAnalyticsRequest(
            tenantRef: null,
            siteRef: '1',
            propertyRef: 'prop_sync',
            startDate: '2026-07-01',
            endDate: '2026-07-07',
            providerKey: 'fake_local',
        );

        $result = $this->sync->sync($request, [
            'site_id' => '1',
            'provider_context' => ['enabled_providers' => ['manual_import', 'fake_local']],
        ]);
        self::assertTrue($result['success'], (string) ($result['error_code'] ?? $result['error_message'] ?? 'sync failed'));
        self::assertSame(GscSyncStage::Completed->value, $result['stage']);

        $operation = $this->sync->getOperation($result['operation_ref']);
        self::assertIsArray($operation);
        self::assertArrayHasKey('completed_at', $operation);
    }

    public function test_partial_success_when_invalid_rows_present(): void
    {
        $csv = <<<'CSV'
date,query,page,country,device,search_appearance,clicks,impressions,ctr,position
2026-07-01,dịch vụ seo,https://example.test/a,vnm,desktop,,5,50,0.1,8
2026-07-01,bad,https://example.test/b,vnm,desktop,,200,100,0.2,4
CSV;

        $request = new GscSearchAnalyticsRequest(
            tenantRef: null,
            siteRef: '1',
            propertyRef: 'prop_partial',
            startDate: '2026-07-01',
            endDate: '2026-07-07',
            providerKey: 'manual_import',
            options: ['import_payload' => $csv],
        );

        $result = $this->sync->sync($request, [
            'site_id' => '1',
            'provider_context' => ['enabled_providers' => ['manual_import', 'fake_local']],
        ]);
        self::assertTrue($result['success'], (string) ($result['error_code'] ?? $result['error_message'] ?? 'sync failed'));
        self::assertTrue($result['partial'] ?? false);
        self::assertSame(GscSyncStage::PartiallyCompleted->value, $result['stage']);
    }

    public function test_cancel_after_complete_returns_false(): void
    {
        $request = new GscSearchAnalyticsRequest(
            tenantRef: null,
            siteRef: '1',
            propertyRef: 'prop_cancel',
            startDate: '2026-07-01',
            endDate: '2026-07-07',
            providerKey: 'fake_local',
        );

        $result = $this->sync->sync($request, [
            'site_id' => '1',
            'provider_context' => ['enabled_providers' => ['manual_import', 'fake_local']],
        ]);
        self::assertTrue($result['success'], (string) ($result['error_code'] ?? $result['error_message'] ?? 'sync failed'));
        self::assertFalse($this->sync->cancel($result['operation_ref']));
    }
}
