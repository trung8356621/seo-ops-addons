<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscSyncStage;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Data\GscSearchAnalyticsRequest;

/**
 * GSC sync operation — stages, lock, partial success.
 */
final class GscSyncOperationService
{
    /** @var array<string, array<string, mixed>> */
    private static array $operations = [];

    public function __construct(
        private readonly GscSyncLockService $lockService,
        private readonly GscProviderResolver $providerResolver,
        private readonly GscDailyMetricPersistService $persistService,
        private readonly GscPageArticleMapper $pageMapper,
        private readonly GscQueryKeywordMapper $keywordMapper,
        private readonly GscPerformanceAggregationService $aggregation,
        private readonly GscOpportunityDetectionService $opportunityDetection,
    ) {}

    /**
     * @param  array<string, mixed>  $context  site_id, page_candidates?, keyword_candidates?, provider_context?
     * @return array<string, mixed>
     */
    public function sync(GscSearchAnalyticsRequest $request, array $context = []): array
    {
        $operationRef = $this->issueOperationRef($request->propertyRef);
        $siteId = (string) ($context['site_id'] ?? $request->siteRef ?? '');

        self::$operations[$operationRef] = [
            'operation_ref' => $operationRef,
            'property_ref' => $request->propertyRef,
            'stage' => GscSyncStage::Preparing->value,
            'started_at' => date('c'),
        ];

        try {
            return $this->lockService->withSyncLock($request->propertyRef, function () use ($request, $context, $operationRef, $siteId): array {
                return $this->runSync($request, $context, $operationRef, $siteId);
            });
        } catch (\Throwable $e) {
            $this->updateStage($operationRef, GscSyncStage::Failed, ['error' => $e->getMessage()]);

            return [
                'operation_ref' => $operationRef,
                'stage' => GscSyncStage::Failed->value,
                'success' => false,
                'error_code' => 'gsc.sync_failed',
                'error_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOperation(string $operationRef): ?array
    {
        return self::$operations[$operationRef] ?? null;
    }

    public function cancel(string $operationRef): bool
    {
        if (! isset(self::$operations[$operationRef])) {
            return false;
        }

        $terminal = [
            GscSyncStage::Completed->value,
            GscSyncStage::PartiallyCompleted->value,
            GscSyncStage::Failed->value,
            'cancelled',
        ];

        if (in_array((string) (self::$operations[$operationRef]['stage'] ?? ''), $terminal, true)) {
            return false;
        }

        self::$operations[$operationRef]['stage'] = 'cancelled';
        self::$operations[$operationRef]['cancelled_at'] = date('c');

        return true;
    }

    /** @internal test helper */
    public static function resetOperations(): void
    {
        self::$operations = [];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runSync(GscSearchAnalyticsRequest $request, array $context, string $operationRef, string $siteId): array
    {
        $this->updateStage($operationRef, GscSyncStage::Fetching);

        $resolved = $this->providerResolver->resolve($request, (array) ($context['provider_context'] ?? []));
        if ($resolved['provider'] === null) {
            $this->updateStage($operationRef, GscSyncStage::Failed, ['error_code' => $resolved['error_code']]);

            return [
                'operation_ref' => $operationRef,
                'stage' => GscSyncStage::Failed->value,
                'success' => false,
                'error_code' => $resolved['error_code'],
                'metadata' => $resolved['metadata'],
            ];
        }

        $result = $resolved['provider']->collectAnalytics($request);
        if (! $result->success) {
            $this->updateStage($operationRef, GscSyncStage::Failed, ['error_code' => $result->errorCode]);

            return [
                'operation_ref' => $operationRef,
                'stage' => GscSyncStage::Failed->value,
                'success' => false,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
            ];
        }

        $this->updateStage($operationRef, GscSyncStage::Normalizing);
        $normalizedRows = $result->rows;

        $this->updateStage($operationRef, GscSyncStage::Persisting);
        $persistContext = [
            'property_id' => (int) ($context['property_id'] ?? 0),
            'tenant_id' => $context['tenant_id'] ?? null,
            'site_id' => (int) ($context['site_id_int'] ?? $context['site_id'] ?? 0),
            'search_type' => (string) ($context['search_type'] ?? 'web'),
            'source' => (string) ($context['source'] ?? $request->providerKey),
            'source_ref' => $operationRef,
        ];
        $persisted = $this->persistService->upsertMany($request->propertyRef, $normalizedRows, $persistContext);

        $this->updateStage($operationRef, GscSyncStage::Mapping);
        $pageCandidates = (array) ($context['page_candidates'] ?? []);
        $keywordCandidates = (array) ($context['keyword_candidates'] ?? []);
        $mappings = [];

        foreach ($normalizedRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $page = (string) ($row['normalized_page'] ?? $row['page'] ?? '');
            $query = (string) ($row['query'] ?? '');
            $normalizedQuery = (string) ($row['normalized_query'] ?? '');
            $pageMap = $this->pageMapper->map($page, $siteId, $pageCandidates);
            $keywordMap = $this->keywordMapper->map($query, $siteId, $keywordCandidates);
            $mappings[] = [
                'data_hash' => $row['data_hash'] ?? null,
                'normalized_page' => $page,
                'normalized_query' => $normalizedQuery !== '' ? $normalizedQuery : $query,
                'page_mapping' => $pageMap,
                'keyword_mapping' => $keywordMap,
            ];
        }

        $this->updateStage($operationRef, GscSyncStage::Aggregating);
        $aggregated = $this->aggregation->aggregate($normalizedRows);

        $this->updateStage($operationRef, GscSyncStage::Detecting);
        $opportunities = [];
        $queries = array_values(array_unique(array_map(
            static fn (array $r): string => (string) ($r['normalized_query'] ?? ''),
            array_filter($normalizedRows, 'is_array'),
        )));

        foreach ($queries as $normalizedQuery) {
            if ($normalizedQuery === '') {
                continue;
            }

            $queryRows = array_values(array_filter(
                $normalizedRows,
                static fn (array $r): bool => (string) ($r['normalized_query'] ?? '') === $normalizedQuery,
            ));

            $keywordRef = null;
            foreach ($mappings as $mapping) {
                if (($mapping['keyword_mapping']['keyword_ref'] ?? null) !== null) {
                    $keywordRef = $mapping['keyword_mapping']['keyword_ref'];
                    break;
                }
            }

            $opportunities = array_merge(
                $opportunities,
                $this->opportunityDetection->detect($queryRows, [], [
                    'normalized_query' => $normalizedQuery,
                    'keyword_ref' => $keywordRef,
                ]),
            );
        }

        $this->updateStage($operationRef, GscSyncStage::Finalizing);

        $invalidCount = (int) ($result->metadata['invalid_count'] ?? 0);
        $stage = $invalidCount > 0 && $persisted['rows'] !== []
            ? GscSyncStage::PartiallyCompleted
            : GscSyncStage::Completed;

        $this->updateStage($operationRef, $stage, [
            'persisted' => $persisted,
            'aggregated' => $aggregated,
            'mappings_count' => count($mappings),
            'opportunities_count' => count($opportunities),
        ]);

        return [
            'operation_ref' => $operationRef,
            'stage' => $stage->value,
            'success' => true,
            'row_count' => count($normalizedRows),
            'persisted' => $persisted,
            'aggregated' => $aggregated,
            'mappings' => $mappings,
            'opportunities' => $opportunities,
            'partial' => $stage === GscSyncStage::PartiallyCompleted,
        ];
    }

    private function issueOperationRef(string $propertyRef): string
    {
        return 'gsc_op_'.substr(hash('sha256', $propertyRef.microtime(true)), 0, 16);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function updateStage(string $operationRef, GscSyncStage $stage, array $extra = []): void
    {
        if (! isset(self::$operations[$operationRef])) {
            return;
        }

        self::$operations[$operationRef]['stage'] = $stage->value;
        self::$operations[$operationRef]['updated_at'] = date('c');
        foreach ($extra as $key => $value) {
            self::$operations[$operationRef][$key] = $value;
        }

        if (in_array($stage, [GscSyncStage::Completed, GscSyncStage::PartiallyCompleted, GscSyncStage::Failed], true)) {
            self::$operations[$operationRef]['completed_at'] = date('c');
        }
    }
}
