<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscDevice;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscSearchAppearance;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Data\GscImportPreview;

/**
 * Parse + validate CSV GSC export: date,query,page,country,device,search_appearance,clicks,impressions,ctr,position.
 */
final class GscImportPreviewService
{
    /** @var list<string> */
    private const REQUIRED_COLUMNS = [
        'date', 'query', 'page', 'country', 'device', 'search_appearance',
        'clicks', 'impressions', 'ctr', 'position',
    ];

    public function __construct(
        private readonly GscQueryNormalizationService $queryNormalizer,
        private readonly GscPageNormalizationService $pageNormalizer,
        private readonly GscFactHashService $factHash,
    ) {}

    public function preview(string $csvPayload, string $propertyRef = 'manual_import'): GscImportPreview
    {
        $rows = $this->parseCsv($csvPayload);
        $validRows = [];
        $invalidRows = [];
        $duplicateRows = [];
        $seenHashes = [];

        foreach ($rows as $index => $row) {
            $validated = $this->validateRow($row, $index, $propertyRef);
            if (($validated['valid'] ?? false) !== true) {
                $invalidRows[] = $validated;
                continue;
            }

            $dataHash = (string) ($validated['data_hash'] ?? '');
            if ($dataHash !== '' && isset($seenHashes[$dataHash])) {
                $duplicateRows[] = [
                    'index' => $index,
                    'data_hash' => $dataHash,
                    'first_index' => $seenHashes[$dataHash],
                ];
                continue;
            }

            if ($dataHash !== '') {
                $seenHashes[$dataHash] = $index;
            }

            $validRows[] = $validated;
        }

        return new GscImportPreview(
            validRows: $validRows,
            invalidRows: $invalidRows,
            duplicateRows: $duplicateRows,
            summary: [
                'total' => count($rows),
                'valid' => count($validRows),
                'invalid' => count($invalidRows),
                'duplicate' => count($duplicateRows),
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseCsv(string $payload): array
    {
        $payload = trim($payload);
        if ($payload === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $payload) ?: [];
        if ($lines === []) {
            return [];
        }

        $headerLine = array_shift($lines);
        if (! is_string($headerLine)) {
            return [];
        }

        $headers = array_map(
            static fn (string $h): string => mb_strtolower(trim($h), 'UTF-8'),
            str_getcsv($headerLine),
        );

        $rows = [];
        foreach ($lines as $line) {
            if (! is_string($line) || trim($line) === '') {
                continue;
            }

            $cells = str_getcsv($line);
            if ($cells === []) {
                continue;
            }

            $assoc = [];
            foreach ($headers as $i => $header) {
                $assoc[$header] = isset($cells[$i]) ? trim((string) $cells[$i]) : '';
            }
            $rows[] = $assoc;
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function validateRow(array $row, int $index, string $propertyRef): array
    {
        foreach (self::REQUIRED_COLUMNS as $column) {
            if (! array_key_exists($column, $row)) {
                return ['index' => $index, 'valid' => false, 'reason' => 'missing_column', 'column' => $column];
            }
        }

        $date = trim($row['date']);
        if ($date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['index' => $index, 'valid' => false, 'reason' => 'invalid_date', 'date' => $date];
        }

        $queryAnalysis = $this->queryNormalizer->analyze($row['query']);
        if (! $queryAnalysis->isValid) {
            return [
                'index' => $index,
                'valid' => false,
                'reason' => 'invalid_query',
                'failure_code' => $queryAnalysis->failureCode,
            ];
        }

        $pageNorm = $this->pageNormalizer->normalize($row['page']);
        if ($pageNorm['normalized_url'] === '') {
            return ['index' => $index, 'valid' => false, 'reason' => 'invalid_page', 'page' => $row['page']];
        }

        $clicks = (int) $row['clicks'];
        $impressions = (int) $row['impressions'];
        if ($clicks < 0 || $impressions < 0) {
            return ['index' => $index, 'valid' => false, 'reason' => 'negative_metrics'];
        }

        if ($clicks > $impressions) {
            return [
                'index' => $index,
                'valid' => false,
                'reason' => 'clicks_exceed_impressions',
                'clicks' => $clicks,
                'impressions' => $impressions,
            ];
        }

        $device = GscDevice::tryFromLoose($row['device'])?->value ?? mb_strtolower(trim($row['device']), 'UTF-8');
        $searchAppearance = GscSearchAppearance::tryFromLoose($row['search_appearance'])->value;
        $country = mb_strtolower(trim($row['country']), 'UTF-8');

        $position = $this->parsePosition($row['position'], $impressions);
        if ($impressions > 0 && $position === null) {
            return ['index' => $index, 'valid' => false, 'reason' => 'invalid_position', 'position' => $row['position']];
        }

        $ctr = $impressions > 0 ? round($clicks / $impressions, 6) : 0.0;

        $propertyRef = trim($propertyRef) !== '' ? trim($propertyRef) : 'manual_import';
        $identityHash = $this->factHash->identityHash(
            $propertyRef,
            $date,
            $queryAnalysis->normalized,
            $pageNorm['normalized_url'],
            $country,
            $device,
            $searchAppearance,
        );
        $dataHash = $this->factHash->dataHash(
            $propertyRef,
            $date,
            $queryAnalysis->normalized,
            $pageNorm['normalized_url'],
            $country,
            $device,
            $searchAppearance,
        );

        return [
            'index' => $index,
            'valid' => true,
            'date' => $date,
            'query' => $queryAnalysis->displayValue,
            'normalized_query' => $queryAnalysis->normalized,
            'page' => $pageNorm['url'],
            'normalized_page' => $pageNorm['normalized_url'],
            'country' => $country,
            'device' => $device,
            'search_appearance' => $searchAppearance,
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $ctr,
            'position' => $position,
            'identity_hash' => $identityHash,
            'data_hash' => $dataHash,
        ];
    }

    private function parsePosition(string $raw, int $impressions): ?float
    {
        if ($impressions === 0) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $position = (float) $raw;

        return $position > 0 ? round($position, 4) : null;
    }
}
