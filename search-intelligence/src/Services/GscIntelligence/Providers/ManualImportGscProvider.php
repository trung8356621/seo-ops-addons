<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Providers;

use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Contracts\GscIntelligenceProviderInterface;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Data\GscSearchAnalyticsRequest;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Data\GscSearchAnalyticsResult;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscImportPreviewService;

/**
 * Import GSC Search Analytics thủ công từ CSV export.
 */
final class ManualImportGscProvider implements GscIntelligenceProviderInterface
{
    public function __construct(
        private readonly GscImportPreviewService $importPreview,
    ) {}

    public function key(): string
    {
        return 'manual_import';
    }

    public function supports(GscSearchAnalyticsRequest $request): bool
    {
        return $request->providerKey === $this->key()
            || (($request->options['source'] ?? null) === 'manual_import');
    }

    public function collectAnalytics(GscSearchAnalyticsRequest $request): GscSearchAnalyticsResult
    {
        $payload = $request->options['import_payload'] ?? null;
        if (! is_string($payload) || trim($payload) === '') {
            return new GscSearchAnalyticsResult(
                providerKey: $this->key(),
                success: false,
                rows: [],
                errorCode: 'gsc.manual_import.empty_payload',
                errorMessage: 'Import payload is required.',
            );
        }

        $preview = $this->importPreview->preview($payload, $request->propertyRef);

        return new GscSearchAnalyticsResult(
            providerKey: $this->key(),
            success: $preview->validRows !== [],
            rows: $preview->validRows,
            metadata: [
                'preview_summary' => $preview->summary,
                'invalid_count' => count($preview->invalidRows),
                'duplicate_count' => count($preview->duplicateRows),
                'property_ref' => $request->propertyRef,
                'start_date' => $request->startDate,
                'end_date' => $request->endDate,
            ],
            errorCode: $preview->validRows === [] ? 'gsc.manual_import.no_valid_rows' : null,
            collectedAt: date('c'),
        );
    }

    public function health(): array
    {
        return [
            'healthy' => true,
            'code' => null,
            'message' => null,
            'metadata' => [
                'provider' => $this->key(),
                'capabilities' => ['manual_import', 'csv_preview'],
            ],
        ];
    }
}
