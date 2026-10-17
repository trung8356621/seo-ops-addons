<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

use Illuminate\Support\Facades\Cache;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticHttpException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticTimeoutException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticTransportException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticUnavailableException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;

/**
 * Full-snapshot sync of an eligible keyword dataset.
 *
 * Does not embed, cluster, or write MySQL keyword groups.
 */
final class KeywordGroupDatasetSyncService
{
    public function __construct(
        private readonly SemanticAnalyticsClient $client,
        private readonly KeywordGroupDatasetSnapshotBuilder $snapshots,
    ) {}

    /**
     * @param  list<string>|null  $languageVariants
     * @param  array<string, list<array{key: string, group_type: string}>>  $evidenceByRef
     */
    public function syncSite(int $siteId, ?string $language, ?array $languageVariants = null, array $evidenceByRef = []): KeywordGroupDatasetSyncResult
    {
        return $this->syncItems($siteId, $language, $this->snapshots->build($siteId, $language, $languageVariants, $evidenceByRef));
    }

    /**
     * @param  list<array{ref: string, text: string, language: ?string, industry_evidence?: list<array{key: string, group_type: string}>, metadata?: array<string, mixed>}>  $items
     */
    public function syncItems(int $siteId, ?string $language, array $items): KeywordGroupDatasetSyncResult
    {
        if ($siteId <= 0) {
            throw new KeywordGroupDatasetSyncException('site id is required', 'invalid_site');
        }

        $language = $this->language($language);
        $lock = Cache::lock($this->lockKey($siteId, $language), $this->lockSeconds());
        if (! $lock->get()) {
            throw new KeywordGroupDatasetSyncException('dataset sync already running for site '.$siteId, 'in_progress');
        }

        try {
            return $this->syncUnlocked($siteId, $language, $items);
        } catch (KeywordGroupDatasetSyncException $e) {
            throw $e;
        } catch (SemanticHttpException $e) {
            throw new KeywordGroupDatasetSyncException($e->getMessage(), 'transport', $e->httpStatus, $e);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<array{ref: string, text: string, language: ?string, industry_evidence?: list<array{key: string, group_type: string}>, metadata?: array<string, mixed>}>  $items
     */
    private function syncUnlocked(int $siteId, ?string $language, array $items): KeywordGroupDatasetSyncResult
    {
        $scopeRef = (string) $siteId;
        if ($items === []) {
            return new KeywordGroupDatasetSyncResult('empty', null, null, 0, 0);
        }

        $datasetHash = KeywordGroupDatasetCanonical::datasetHash($scopeRef, $language, $items);
        $current = $this->current($scopeRef, $language);
        if ($current !== null && (string) ($current['dataset_hash'] ?? '') === $datasetHash) {
            return new KeywordGroupDatasetSyncResult(
                'unchanged',
                (int) $current['revision'],
                $datasetHash,
                (int) $current['keyword_count'],
                0,
            );
        }

        $batches = array_chunk($this->payloadItems($items), $this->batchSize());
        $session = null;
        try {
            $session = $this->attempt(fn (): array => $this->client->postJson(
                '/v1/keyword-groups/datasets/sessions',
                [
                    'scope_ref' => $scopeRef,
                    'language' => $language,
                    'expected_keyword_count' => count($items),
                    'expected_batch_count' => count($batches),
                ],
                'keyword-dataset-'.$scopeRef,
                $this->timeoutSeconds(),
            ));
            $sessionId = (string) ($session['session_id'] ?? '');
            if ($sessionId === '') {
                throw new KeywordGroupDatasetSyncException('dataset session response has no session_id', 'contract');
            }

            foreach ($batches as $index => $batch) {
                $batchIndex = $index + 1;
                $this->attempt(fn (): array => $this->client->putJson(
                    '/v1/keyword-groups/datasets/sessions/'.$sessionId.'/batches/'.$batchIndex,
                    ['keywords' => $batch],
                    'keyword-dataset-'.$scopeRef.'-batch-'.$batchIndex,
                    $this->timeoutSeconds(),
                ));
            }

            $committed = $this->attempt(fn (): array => $this->client->postJson(
                '/v1/keyword-groups/datasets/sessions/'.$sessionId.'/commit',
                ['dataset_hash' => $datasetHash],
                'keyword-dataset-'.$scopeRef.'-commit',
                $this->timeoutSeconds(),
            ));
        } catch (SemanticTransportException $e) {
            if ($e->httpStatus === 409 && $session !== null && str_contains($e->getMessage(), 'stale session')) {
                return $this->reconcileStale($scopeRef, $language, $datasetHash, count($items), (string) $session['session_id'], $e);
            }
            if ($e->httpStatus === 422 || $e->httpStatus === 409) {
                $this->abort(isset($session['session_id']) ? (string) $session['session_id'] : null);
                throw new KeywordGroupDatasetSyncException($e->getMessage(), 'contract', $e->httpStatus, $e);
            }
            $this->abort(isset($session['session_id']) ? (string) $session['session_id'] : null);
            throw new KeywordGroupDatasetSyncException($e->getMessage(), 'transport', $e->httpStatus, $e);
        } catch (SemanticHttpException $e) {
            $this->abort(isset($session['session_id']) ? (string) $session['session_id'] : null);
            throw new KeywordGroupDatasetSyncException($e->getMessage(), 'transport', $e->httpStatus, $e);
        }

        $status = ($committed['unchanged'] ?? false) === true ? 'unchanged' : 'committed';

        return new KeywordGroupDatasetSyncResult(
            $status,
            (int) ($committed['revision'] ?? 0),
            (string) ($committed['dataset_hash'] ?? $datasetHash),
            (int) ($committed['keyword_count'] ?? count($items)),
            count($batches),
        );
    }

    private function reconcileStale(
        string $scopeRef,
        ?string $language,
        string $datasetHash,
        int $keywordCount,
        string $sessionId,
        SemanticTransportException $error,
    ): KeywordGroupDatasetSyncResult {
        $current = $this->current($scopeRef, $language);
        if ($current !== null && (string) ($current['dataset_hash'] ?? '') === $datasetHash) {
            $this->abort($sessionId);

            return new KeywordGroupDatasetSyncResult(
                'reconciled',
                (int) $current['revision'],
                $datasetHash,
                (int) ($current['keyword_count'] ?? $keywordCount),
                0,
            );
        }

        $this->abort($sessionId);
        throw new KeywordGroupDatasetSyncException(
            'newer dataset revision is already committed',
            'stale_session',
            409,
            $error,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function current(string $scopeRef, ?string $language): ?array
    {
        $url = '/v1/keyword-groups/datasets/'.rawurlencode($scopeRef);
        if ($language !== null && $language !== '') {
            $url .= '?language='.rawurlencode($language);
        }

        try {
            $row = $this->attempt(fn (): array => $this->client->getJson(
                $url,
                'keyword-dataset-'.$scopeRef.'-'.($language ?? '').'-read',
                $this->timeoutSeconds(),
            ));
        } catch (SemanticTransportException $e) {
            if ($e->httpStatus === 404) {
                return null;
            }
            throw $e;
        }

        if ($language !== null && $language !== '' && ($row['language'] ?? null) !== $language) {
            return null;
        }

        return $row;
    }

    private function abort(?string $sessionId): void
    {
        if ($sessionId === null || $sessionId === '') {
            return;
        }
        try {
            $this->client->postJson(
                '/v1/keyword-groups/datasets/sessions/'.$sessionId.'/abort',
                [],
                null,
                $this->timeoutSeconds(),
            );
        } catch (SemanticHttpException) {
            // The failed sync result stays authoritative. Abort is best-effort.
        }
    }

    /**
     * @param  callable(): array<string, mixed>  $call
     * @return array<string, mixed>
     */
    private function attempt(callable $call): array
    {
        $tries = max(1, (int) config('semantic.keyword_group_dataset_retries', 3));
        $last = null;
        for ($attempt = 1; $attempt <= $tries; $attempt++) {
            try {
                return $call();
            } catch (SemanticUnavailableException|SemanticTimeoutException $e) {
                $last = $e;
            }
        }

        throw $last instanceof SemanticHttpException
            ? $last
            : new KeywordGroupDatasetSyncException('dataset request failed', 'transport');
    }

    /**
     * @param  list<array{ref: string, text: string, language: ?string, industry_evidence?: list<array{key: string, group_type: string}>, metadata?: array<string, mixed>}>  $items
     * @return list<array{ref: string, text: string, language: ?string, industry_evidence: list<array{key: string, group_type: string}>, metadata: \stdClass}>
     */
    private function payloadItems(array $items): array
    {
        $payload = [];
        foreach ($items as $item) {
            $metadata = new \stdClass();
            foreach ($item['metadata'] ?? [] as $key => $value) {
                $metadata->{(string) $key} = $value;
            }
            $payload[] = [
                'ref' => (string) $item['ref'],
                'text' => KeywordGroupDatasetCanonical::normalizeText((string) $item['text']),
                'language' => $item['language'] ?? null,
                'industry_evidence' => array_values($item['industry_evidence'] ?? []),
                'metadata' => $metadata,
            ];
        }
        usort($payload, static fn (array $left, array $right): int => strcmp($left['ref'], $right['ref']));

        return $payload;
    }

    private function language(?string $language): ?string
    {
        $language = trim((string) $language);

        return $language === '' ? null : mb_substr($language, 0, 32);
    }

    private function batchSize(): int
    {
        return max(1, min(1000, (int) config('semantic.keyword_group_dataset_batch_size', 1000)));
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('semantic.keyword_group_dataset_timeout', 60));
    }

    private function lockSeconds(): int
    {
        return max(30, (int) config('semantic.keyword_group_dataset_lock_seconds', 600));
    }

    private function lockKey(int $siteId, ?string $language): string
    {
        return 'keyword-group-dataset-sync:'.$siteId.':'.($language ?? '');
    }
}
