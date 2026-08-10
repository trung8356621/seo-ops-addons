<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Providers;

use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Contracts\SerpIntelligenceProviderInterface;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data\SerpImportPreview;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data\SerpProviderResult;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data\SerpQueryRequest;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpResultClassifier;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpUrlNormalizationService;

/**
 * Import SERP thủ công từ JSON hoặc CSV.
 */
final class ManualImportSerpProvider implements SerpIntelligenceProviderInterface
{
    public function __construct(
        private readonly SerpUrlNormalizationService $urlNormalizer,
        private readonly SerpResultClassifier $resultClassifier,
    ) {}

    public function key(): string
    {
        return 'manual_import';
    }

    public function supports(SerpQueryRequest $request): bool
    {
        return $request->providerKey === $this->key()
            || (($request->options['source'] ?? null) === 'manual_import');
    }

    public function collect(SerpQueryRequest $request): SerpProviderResult
    {
        $payload = $request->options['import_payload'] ?? null;
        if (! is_string($payload) || trim($payload) === '') {
            return new SerpProviderResult(
                providerKey: $this->key(),
                success: false,
                results: [],
                errorCode: 'serp.manual_import.empty_payload',
                errorMessage: 'Import payload is required.',
            );
        }

        $format = (string) ($request->options['format'] ?? 'json');
        $preview = $this->preview($payload, $format);
        $features = $format === 'json' ? $this->extractFeatures($payload) : [];

        return new SerpProviderResult(
            providerKey: $this->key(),
            success: $preview->validRows !== [],
            results: $preview->validRows,
            features: $features,
            metadata: [
                'preview_summary' => $preview->summary,
                'invalid_count' => count($preview->invalidRows),
                'duplicate_count' => count($preview->duplicateRows),
                'will_create_snapshot' => $preview->validRows !== [],
            ],
            errorCode: $preview->validRows === [] ? 'serp.manual_import.no_valid_rows' : null,
            collectedAt: $this->extractCapturedAt($payload, $format),
        );
    }

    public function health(): array
    {
        return [
            'healthy' => true,
            'code' => null,
            'message' => null,
            'metadata' => ['provider' => $this->key()],
        ];
    }

    public function preview(string $payload, string $format = 'json'): SerpImportPreview
    {
        $rows = $format === 'csv' ? $this->parseCsv($payload) : $this->parseJson($payload);

        $validRows = [];
        $invalidRows = [];
        $duplicateRows = [];
        $unknownTypeRows = [];
        $missingUrlRows = [];
        $seenUrls = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $invalidRows[] = ['index' => $index, 'reason' => 'invalid_row_shape'];
                continue;
            }

            $url = trim((string) ($row['url'] ?? $row['link'] ?? ''));
            if ($url === '') {
                $missingUrlRows[] = ['index' => $index, 'row' => $row];
                continue;
            }

            $normalized = $this->urlNormalizer->normalize($url);
            if ($normalized['normalized_url'] === '') {
                $invalidRows[] = ['index' => $index, 'reason' => 'invalid_url'];
                continue;
            }

            if (isset($seenUrls[$normalized['normalized_url']])) {
                $duplicateRows[] = ['index' => $index, 'normalized_url' => $normalized['normalized_url']];
                continue;
            }

            $seenUrls[$normalized['normalized_url']] = true;
            $classified = $this->resultClassifier->classify($row);
            if ($classified['type']->value === 'other' && ($classified['provider_type'] ?? '') !== '' && ($classified['metadata']['mapped'] ?? false) === false) {
                $unknownTypeRows[] = ['index' => $index, 'provider_type' => $classified['provider_type']];
            }

            $validRows[] = array_merge($row, [
                'url' => $url,
                'normalized_url' => $normalized['normalized_url'],
                'domain' => $normalized['normalized_domain'],
                'canonical_type' => $classified['type']->value,
                'provider_type' => $classified['provider_type'],
                'position' => (int) ($row['position'] ?? ($index + 1)),
            ]);
        }

        return new SerpImportPreview(
            validRows: $validRows,
            invalidRows: $invalidRows,
            duplicateRows: $duplicateRows,
            unknownTypeRows: $unknownTypeRows,
            missingUrlRows: $missingUrlRows,
            summary: [
                'total' => count($rows),
                'valid' => count($validRows),
                'invalid' => count($invalidRows),
                'duplicates' => count($duplicateRows),
                'unknown_types' => count($unknownTypeRows),
                'missing_urls' => count($missingUrlRows),
                'will_create_snapshot' => count($validRows) > 0,
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private function parseJson(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['results']) && is_array($decoded['results'])) {
            return array_values(array_filter($decoded['results'], 'is_array'));
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /** @return list<array<string, mixed>> */
    private function parseCsv(string $payload): array
    {
        $lines = preg_split('/\R/u', trim($payload)) ?: [];
        if ($lines === []) {
            return [];
        }

        $headers = str_getcsv(array_shift($lines) ?: '');
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line);
            $row = [];
            foreach ($headers as $i => $header) {
                if (! is_string($header) || trim($header) === '') {
                    continue;
                }
                $row[trim($header)] = $values[$i] ?? null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function extractFeatures(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded) || ! isset($decoded['features']) || ! is_array($decoded['features'])) {
            return [];
        }

        return array_values(array_filter($decoded['features'], 'is_array'));
    }

    private function extractCapturedAt(string $payload, string $format): ?string
    {
        if ($format !== 'json') {
            return null;
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return null;
        }

        $capturedAt = $decoded['captured_at'] ?? null;

        return is_string($capturedAt) && trim($capturedAt) !== '' ? trim($capturedAt) : null;
    }
}
