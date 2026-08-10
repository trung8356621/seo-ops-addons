<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data\SerpProviderResult;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data\SerpQueryRequest;
use RuntimeException;

/**
 * CollectSerpSnapshots flow — lock, stages, partial success.
 */
final class SerpCollectionOperationService
{
    /** @var array<string, array<string, mixed>> */
    private static array $operations = [];

    public function __construct(
        private readonly SerpCollectionLockService $lockService,
        private readonly SerpProviderResolver $providerResolver,
        private readonly SerpSnapshotPersistService $persistService,
    ) {}

    /**
     * @param  list<string>  $queryRefs
     * @return array{operation_ref: string, stage: string, results: list<array<string, mixed>>}
     */
    public function collect(string $workspaceRef, array $queryRefs, ?string $providerKey = null): array
    {
        $operationRef = $this->issueOperationRef($workspaceRef);
        $results = [];
        $successCount = 0;
        $failCount = 0;

        self::$operations[$operationRef] = [
            'operation_ref' => $operationRef,
            'workspace_ref' => $workspaceRef,
            'stage' => 'collecting',
            'query_refs' => $queryRefs,
            'started_at' => date('c'),
            'results' => [],
        ];

        foreach ($queryRefs as $queryRef) {
            $queryRef = trim($queryRef);
            if ($queryRef === '') {
                continue;
            }

            try {
                $queryId = KeywordIntelligencePublicRef::resolveSerpQueryIdStrict($queryRef);
                $query = SeoSerpQuery::query()->find($queryId);
                if (! $query instanceof SeoSerpQuery) {
                    throw new RuntimeException('SERP query không tồn tại.');
                }

                $row = $this->lockService->withCollectionLock($queryRef, function () use ($query, $queryRef, $providerKey): array {
                    return $this->collectSingle($query, $queryRef, $providerKey);
                });

                $results[] = $row;
                if (($row['success'] ?? false) === true) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            } catch (\Throwable $e) {
                $results[] = [
                    'query_ref' => $queryRef,
                    'success' => false,
                    'error_code' => 'serp.collection_failed',
                    'error_message' => $e->getMessage(),
                ];
                $failCount++;
            }
        }

        $stage = match (true) {
            $successCount === 0 => 'failed',
            $failCount > 0 => 'partially_completed',
            default => 'completed',
        };

        self::$operations[$operationRef]['stage'] = $stage;
        self::$operations[$operationRef]['results'] = $results;
        self::$operations[$operationRef]['completed_at'] = date('c');

        return [
            'operation_ref' => $operationRef,
            'stage' => $stage,
            'results' => $results,
        ];
    }

    public function cancel(string $operationRef): bool
    {
        if (! isset(self::$operations[$operationRef])) {
            return false;
        }

        if (in_array(self::$operations[$operationRef]['stage'] ?? '', ['completed', 'partially_completed', 'failed', 'cancelled'], true)) {
            return false;
        }

        self::$operations[$operationRef]['stage'] = 'cancelled';
        self::$operations[$operationRef]['cancelled_at'] = date('c');

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOperation(string $operationRef): ?array
    {
        return self::$operations[$operationRef] ?? null;
    }

    /** @internal test helper */
    public static function resetOperations(): void
    {
        self::$operations = [];
    }

    /**
     * @return array{query_ref: string, success: bool, snapshot_ref?: string, error_code?: string, error_message?: string}
     */
    private function collectSingle(SeoSerpQuery $query, string $queryRef, ?string $providerKey): array
    {
        $key = $providerKey ?? (string) ($query->provider_key ?? 'manual_import');
        $request = new SerpQueryRequest(
            tenantRef: $query->tenant_id !== null ? (string) $query->tenant_id : null,
            siteRef: (string) $query->site_id,
            query: $query->query,
            displayQuery: $query->query,
            normalizedQuery: $query->normalized_query,
            language: (string) ($query->language ?? ''),
            country: (string) ($query->country ?? ''),
            location: $query->location,
            device: (string) ($query->device?->value ?? $query->device ?? 'desktop'),
            searchEngine: (string) ($query->search_engine ?? 'google'),
            providerKey: $key,
            options: is_array($query->settings) ? $query->settings : [],
        );

        $resolved = $this->providerResolver->resolve($request);
        if ($resolved['provider'] === null) {
            return [
                'query_ref' => $queryRef,
                'success' => false,
                'error_code' => $resolved['error_code'] ?? 'serp_provider.not_configured',
            ];
        }

        $providerResult = $resolved['provider']->collect($request);
        if (! $providerResult instanceof SerpProviderResult || ! $providerResult->success) {
            return [
                'query_ref' => $queryRef,
                'success' => false,
                'error_code' => $providerResult->errorCode ?? 'serp.collection_failed',
                'error_message' => $providerResult->errorMessage,
            ];
        }

        $pending = $this->persistService->createPending($query, $key);
        $snapshot = $this->persistService->persistFromProviderResult($pending, $providerResult);

        return [
            'query_ref' => $queryRef,
            'success' => true,
            'snapshot_ref' => $snapshot->public_ref,
        ];
    }

    private function issueOperationRef(string $workspaceRef): string
    {
        return 'srpop_'.substr(hash('xxh3', $workspaceRef.'|'.microtime(true)), 0, 16);
    }
}
