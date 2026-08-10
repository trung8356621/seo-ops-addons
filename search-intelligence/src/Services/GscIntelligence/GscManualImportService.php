<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Data\GscImportPreview;

/**
 * Manual CSV import — preview + dual-write facts (memory + Eloquent khi có property context).
 */
final class GscManualImportService
{
    public function __construct(
        private readonly GscImportPreviewService $importPreview,
        private readonly GscDailyMetricPersistService $persistService,
    ) {}

    public function preview(string $csvPayload, string $propertyRef): GscImportPreview
    {
        return $this->importPreview->preview($csvPayload, $propertyRef);
    }

    /**
     * @param  array{property_id?: int, tenant_id?: int|null, site_id?: int, search_type?: string, source?: string, source_ref?: string|null}  $propertyContext
     * @return array{preview: GscImportPreview, persisted: array{inserted: int, updated: int, rows: list<array<string, mixed>>}}
     */
    public function import(string $csvPayload, string $propertyRef, array $propertyContext = []): array
    {
        $preview = $this->preview($csvPayload, $propertyRef);

        if ($preview->validRows === []) {
            return [
                'preview' => $preview,
                'persisted' => ['inserted' => 0, 'updated' => 0, 'rows' => []],
            ];
        }

        $persisted = $this->persistService->upsertMany($propertyRef, $preview->validRows, array_merge([
            'source' => 'manual_import',
        ], $propertyContext));

        return [
            'preview' => $preview,
            'persisted' => $persisted,
        ];
    }
}
