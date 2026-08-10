<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscSearchType;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscDailyMetric;
use Throwable;

/**
 * Upsert daily facts by data_hash — REPLACE metric values, không cộng dồn.
 * Dual-write: in-memory (unit tests / same-request) + Eloquent `omi_seo_ai` khi có property context.
 */
final class GscDailyMetricPersistService
{
    public const ALGORITHM_VERSION = '1.0.0';

    /** @var array<string, array<string, mixed>> */
    private static array $facts = [];

    public function __construct(
        private readonly GscFactHashService $factHash,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     * @param  array{property_id?: int, tenant_id?: int|null, site_id?: int, search_type?: string, source?: string, source_ref?: string|null}  $context
     * @return array<string, mixed>
     */
    public function upsert(string $propertyRef, array $row, array $context = []): array
    {
        $dataHash = (string) ($row['data_hash'] ?? '');
        if ($dataHash === '') {
            $dataHash = $this->factHash->dataHash(
                $propertyRef,
                (string) ($row['date'] ?? ''),
                (string) ($row['normalized_query'] ?? $row['query'] ?? ''),
                (string) ($row['normalized_page'] ?? $row['page'] ?? ''),
                (string) ($row['country'] ?? ''),
                (string) ($row['device'] ?? ''),
                (string) ($row['search_appearance'] ?? 'none'),
            );
        }

        $normalizedQuery = (string) ($row['normalized_query'] ?? '');
        $normalizedPage = (string) ($row['normalized_page'] ?? '');

        $record = [
            'property_ref' => $propertyRef,
            'date' => (string) ($row['date'] ?? ''),
            'query' => (string) ($row['query'] ?? ''),
            'normalized_query' => $normalizedQuery,
            'normalized_query_hash' => $normalizedQuery !== '' ? hash('sha256', $normalizedQuery) : null,
            'page' => (string) ($row['page'] ?? ''),
            'normalized_page' => $normalizedPage,
            'normalized_page_hash' => $normalizedPage !== '' ? hash('sha256', $normalizedPage) : null,
            'country' => (string) ($row['country'] ?? ''),
            'device' => (string) ($row['device'] ?? ''),
            'search_appearance' => (string) ($row['search_appearance'] ?? 'none'),
            'clicks' => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr' => (float) ($row['ctr'] ?? 0.0),
            'position' => isset($row['position']) ? (float) $row['position'] : null,
            'identity_hash' => (string) ($row['identity_hash'] ?? $this->factHash->identityHash(
                $propertyRef,
                (string) ($row['date'] ?? ''),
                $normalizedQuery,
                $normalizedPage,
                (string) ($row['country'] ?? ''),
                (string) ($row['device'] ?? ''),
                (string) ($row['search_appearance'] ?? 'none'),
            )),
            'data_hash' => $dataHash,
            'algorithm_version' => self::ALGORITHM_VERSION,
            'updated_at' => date('c'),
        ];

        // REPLACE — never sum clicks/impressions on upsert.
        self::$facts[$dataHash] = $record;

        $this->persistToDatabase($record, $context);

        return self::$facts[$dataHash];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{property_id?: int, tenant_id?: int|null, site_id?: int, search_type?: string, source?: string, source_ref?: string|null}  $context
     * @return array{inserted: int, updated: int, rows: list<array<string, mixed>>}
     */
    public function upsertMany(string $propertyRef, array $rows, array $context = []): array
    {
        $inserted = 0;
        $updated = 0;
        $persisted = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $hash = (string) ($row['data_hash'] ?? '');
            $existed = $hash !== '' && (isset(self::$facts[$hash]) || $this->databaseRowExists($hash, $context));
            $record = $this->upsert($propertyRef, $row, $context);
            $persisted[] = $record;
            if ($existed) {
                $updated++;
            } else {
                $inserted++;
            }
        }

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'rows' => $persisted,
        ];
    }

    public function findByDataHash(string $dataHash): ?array
    {
        if (isset(self::$facts[$dataHash])) {
            return self::$facts[$dataHash];
        }

        try {
            $row = SeoGscDailyMetric::query()->where('data_hash', $dataHash)->first();
            if (! $row instanceof SeoGscDailyMetric) {
                return null;
            }

            return $this->modelToFactArray($row, (string) ($row->property?->public_ref ?? ''));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allFacts(): array
    {
        return array_values(self::$facts);
    }

    /**
     * Memory + DB facts for property (DB wins when data_hash collision unless memory newer in-process).
     *
     * @return list<array<string, mixed>>
     */
    public function factsForProperty(string $propertyRef, ?int $propertyId = null): array
    {
        $byHash = [];

        foreach (self::$facts as $hash => $row) {
            if ((string) ($row['property_ref'] ?? '') === $propertyRef) {
                $byHash[$hash] = $row;
            }
        }

        if ($propertyId !== null && $propertyId > 0) {
            try {
                $dbRows = SeoGscDailyMetric::query()
                    ->where('property_id', $propertyId)
                    ->get();

                foreach ($dbRows as $model) {
                    if (! $model instanceof SeoGscDailyMetric) {
                        continue;
                    }

                    $fact = $this->modelToFactArray($model, $propertyRef);
                    $hash = (string) ($fact['data_hash'] ?? '');
                    if ($hash === '' || isset($byHash[$hash])) {
                        continue;
                    }
                    $byHash[$hash] = $fact;
                }
            } catch (Throwable) {
                // Pure PHPUnit / connection not bootstrapped — memory only.
            }
        }

        return array_values($byHash);
    }

    /** @internal test helper */
    public static function resetFacts(): void
    {
        self::$facts = [];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array{property_id?: int, tenant_id?: int|null, site_id?: int, search_type?: string, source?: string, source_ref?: string|null}  $context
     */
    private function persistToDatabase(array $record, array $context): void
    {
        $propertyId = (int) ($context['property_id'] ?? 0);
        $siteId = (int) ($context['site_id'] ?? 0);
        if ($propertyId <= 0 || $siteId <= 0) {
            return;
        }

        try {
            $dataHash = (string) ($record['data_hash'] ?? '');
            if ($dataHash === '') {
                return;
            }

            $metric = SeoGscDailyMetric::query()
                ->where('property_id', $propertyId)
                ->where('data_hash', $dataHash)
                ->first();

            if (! $metric instanceof SeoGscDailyMetric) {
                $metric = new SeoGscDailyMetric([
                    'tenant_id' => $context['tenant_id'] ?? null,
                    'site_id' => $siteId,
                    'property_id' => $propertyId,
                    'data_hash' => $dataHash,
                ]);
            }

            $searchType = (string) ($context['search_type'] ?? $record['search_type'] ?? 'web');
            $metric->metric_date = (string) ($record['date'] ?? '');
            $metric->search_type = GscSearchType::tryFrom($searchType) ?? GscSearchType::Web;
            $metric->query = (string) ($record['query'] ?? '') ?: null;
            $metric->normalized_query = (string) ($record['normalized_query'] ?? '') ?: null;
            $metric->normalized_query_hash = $record['normalized_query_hash'] ?? null;
            $metric->page = (string) ($record['page'] ?? '') ?: null;
            $metric->normalized_page = (string) ($record['normalized_page'] ?? '') ?: null;
            $metric->normalized_page_hash = $record['normalized_page_hash'] ?? null;
            $metric->country = (string) ($record['country'] ?? '') ?: null;
            $metric->device = (string) ($record['device'] ?? '') ?: null;
            $metric->search_appearance = (string) ($record['search_appearance'] ?? '') ?: null;
            $metric->clicks = (int) ($record['clicks'] ?? 0);
            $metric->impressions = (int) ($record['impressions'] ?? 0);
            $metric->ctr = (float) ($record['ctr'] ?? 0.0);
            $metric->position = isset($record['position']) ? (float) $record['position'] : null;
            $metric->source = (string) ($context['source'] ?? 'manual_import');
            $metric->source_ref = isset($context['source_ref']) ? (string) $context['source_ref'] : null;
            $metric->save();
        } catch (Throwable) {
            // Fail soft — memory row still available for same-request detect/aggregate.
        }
    }

    /**
     * @param  array{property_id?: int}  $context
     */
    private function databaseRowExists(string $dataHash, array $context): bool
    {
        $propertyId = (int) ($context['property_id'] ?? 0);
        if ($dataHash === '' || $propertyId <= 0) {
            return false;
        }

        try {
            return SeoGscDailyMetric::query()
                ->where('property_id', $propertyId)
                ->where('data_hash', $dataHash)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function modelToFactArray(SeoGscDailyMetric $model, string $propertyRef): array
    {
        $normalizedQuery = (string) ($model->normalized_query ?? '');
        $normalizedPage = (string) ($model->normalized_page ?? '');

        return [
            'property_ref' => $propertyRef,
            'date' => $model->metric_date?->format('Y-m-d') ?? (string) $model->metric_date,
            'query' => (string) ($model->query ?? ''),
            'normalized_query' => $normalizedQuery,
            'normalized_query_hash' => $model->normalized_query_hash,
            'page' => (string) ($model->page ?? ''),
            'normalized_page' => $normalizedPage,
            'normalized_page_hash' => $model->normalized_page_hash,
            'country' => (string) ($model->country ?? ''),
            'device' => (string) ($model->device ?? ''),
            'search_appearance' => (string) ($model->search_appearance ?? 'none'),
            'clicks' => (int) $model->clicks,
            'impressions' => (int) $model->impressions,
            'ctr' => (float) $model->ctr,
            'position' => $model->position !== null ? (float) $model->position : null,
            'data_hash' => (string) $model->data_hash,
            'algorithm_version' => self::ALGORITHM_VERSION,
            'updated_at' => $model->updated_at?->toIso8601String() ?? date('c'),
        ];
    }
}
