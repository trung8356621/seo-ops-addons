<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpQuery;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Contracts\SerpIntelligenceProviderRegistry;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data\SerpImportPreview;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Providers\ManualImportSerpProvider;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpCollectionLockService;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpCollectionOperationService;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpImportSnapshotService;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpProviderResolver;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpResultClassifier;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpSnapshotPersistService;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpUrlNormalizationService;
use PHPUnit\Framework\TestCase;

final class SerpIntelligenceApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        SerpCollectionOperationService::resetOperations();
        parent::tearDown();
    }

    public function test_import_checksum_is_stable_for_same_preview(): void
    {
        $service = new SerpImportSnapshotService(
            new ManualImportSerpProvider(new SerpUrlNormalizationService(), new SerpResultClassifier()),
            new SerpSnapshotPersistService(new SerpResultClassifier(), new SerpUrlNormalizationService()),
        );

        $query = new SeoSerpQuery([
            'id' => 42,
            'normalized_query' => 'seo audit tool',
        ]);

        $preview = new SerpImportPreview(
            validRows: [['url' => 'https://example.com/a', 'position' => 1]],
            invalidRows: [],
            duplicateRows: [],
            unknownTypeRows: [],
            missingUrlRows: [],
            summary: ['valid' => 1],
        );

        $a = $service->computeImportChecksum($query, $preview);
        $b = $service->computeImportChecksum($query, $preview);

        $this->assertSame($a, $b);
        $this->assertNotSame('', $a);
    }

    public function test_collection_operation_records_failed_query_and_blocks_cancel_after_complete(): void
    {
        // Invalid ref fail trước khi acquire lock — stub lock không cần Cache facade.
        $lock = new class extends SerpCollectionLockService {
            public function __construct()
            {
                // skip parent DI
            }

            public function withCollectionLock(string $serpQueryRef, callable $callback, int $waitSeconds = 0): mixed
            {
                return $callback('test-owner');
            }
        };

        $collection = new SerpCollectionOperationService(
            $lock,
            new SerpProviderResolver(new SerpIntelligenceProviderRegistry()),
            new SerpSnapshotPersistService(new SerpResultClassifier(), new SerpUrlNormalizationService()),
        );

        $result = $collection->collect('kww_test', ['not-a-valid-ref']);
        $this->assertArrayHasKey('operation_ref', $result);
        $this->assertSame('failed', $result['stage']);

        $operation = $collection->getOperation($result['operation_ref']);
        $this->assertIsArray($operation);
        $this->assertFalse($collection->cancel($result['operation_ref']));
    }
}
