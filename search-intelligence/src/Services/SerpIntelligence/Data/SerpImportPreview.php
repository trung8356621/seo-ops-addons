<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data;

/**
 * Preview kết quả import manual JSON/CSV trước khi persist.
 */
final class SerpImportPreview
{
    /**
     * @param  list<array<string, mixed>>  $validRows
     * @param  list<array<string, mixed>>  $invalidRows
     * @param  list<array<string, mixed>>  $duplicateRows
     * @param  list<array<string, mixed>>  $unknownTypeRows
     * @param  list<array<string, mixed>>  $missingUrlRows
     * @param  array<string, int>  $summary
     */
    public function __construct(
        public readonly array $validRows,
        public readonly array $invalidRows,
        public readonly array $duplicateRows,
        public readonly array $unknownTypeRows,
        public readonly array $missingUrlRows,
        public readonly array $summary,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'valid_rows' => $this->validRows,
            'invalid_rows' => $this->invalidRows,
            'duplicate_rows' => $this->duplicateRows,
            'unknown_type_rows' => $this->unknownTypeRows,
            'missing_url_rows' => $this->missingUrlRows,
            'summary' => $this->summary,
        ];
    }
}
