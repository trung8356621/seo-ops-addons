<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Data;

/**
 * Preview kết quả import manual CSV trước khi persist.
 */
final class GscImportPreview
{
    /**
     * @param  list<array<string, mixed>>  $validRows
     * @param  list<array<string, mixed>>  $invalidRows
     * @param  list<array<string, mixed>>  $duplicateRows
     * @param  array<string, int>  $summary
     */
    public function __construct(
        public readonly array $validRows,
        public readonly array $invalidRows,
        public readonly array $duplicateRows,
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
            'summary' => $this->summary,
        ];
    }
}
