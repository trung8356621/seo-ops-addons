<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpQuery;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpSnapshot;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data\SerpImportPreview;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data\SerpProviderResult;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data\SerpQueryRequest;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Providers\ManualImportSerpProvider;
use RuntimeException;

/**
 * Preview + import snapshot với checksum idempotency.
 */
final class SerpImportSnapshotService
{
    public function __construct(
        private readonly ManualImportSerpProvider $manualProvider,
        private readonly SerpSnapshotPersistService $persistService,
    ) {}

    /**
     * @return array{preview: SerpImportPreview, checksum: string}
     */
    public function preview(SeoSerpQuery $query, string $payload, string $format = 'json'): array
    {
        $request = $this->buildImportRequest($query, $payload, $format);
        $preview = $this->manualProvider->preview($payload, $format);
        $checksum = $this->computeImportChecksum($query, $preview);

        return ['preview' => $preview, 'checksum' => $checksum];
    }

    /**
     * @return array{snapshot_ref: string, checksum: string, already_imported: bool}
     */
    public function import(SeoSerpQuery $query, string $payload, string $format = 'json'): array
    {
        $request = $this->buildImportRequest($query, $payload, $format);
        $preview = $this->manualProvider->preview($payload, $format);
        $checksum = $this->computeImportChecksum($query, $preview);

        $existing = SeoSerpSnapshot::query()
            ->where('serp_query_id', $query->id)
            ->where('normalized_checksum', $checksum)
            ->whereIn('status', ['completed', 'partially_completed'])
            ->first();

        if ($existing instanceof SeoSerpSnapshot) {
            return [
                'snapshot_ref' => $existing->public_ref,
                'checksum' => $checksum,
                'already_imported' => true,
            ];
        }

        $providerResult = $this->manualProvider->collect($request);
        if (! $providerResult->success) {
            throw new RuntimeException($providerResult->errorCode ?? 'serp.import_failed');
        }

        $pending = $this->persistService->createPending($query, 'manual_import');
        $snapshot = $this->persistService->persistFromProviderResult($pending, $providerResult);

        return [
            'snapshot_ref' => $snapshot->public_ref,
            'checksum' => $checksum,
            'already_imported' => false,
        ];
    }

    public function computeImportChecksum(SeoSerpQuery $query, SerpImportPreview $preview): string
    {
        $payload = json_encode([
            'query_id' => $query->id,
            'normalized_query' => $query->normalized_query,
            'valid_rows' => $preview->validRows,
        ], JSON_THROW_ON_ERROR);

        return hash('xxh3', $payload);
    }

    private function buildImportRequest(SeoSerpQuery $query, string $payload, string $format): SerpQueryRequest
    {
        return new SerpQueryRequest(
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
            providerKey: 'manual_import',
            options: [
                'source' => 'manual_import',
                'import_payload' => $payload,
                'format' => $format,
            ],
        );
    }
}
