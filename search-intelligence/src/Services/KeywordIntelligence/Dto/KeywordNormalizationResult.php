<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

/**
 * Kết quả phân tích chuẩn hoá 1 keyword (validate cho import/UI review).
 * Không thay thế KeywordNormalizationService::normalize()/displayKeyword() — bọc thêm
 * validity + lý do thay đổi để Filament/API hiển thị mà không cần parse lại chuỗi.
 */
final class KeywordNormalizationResult
{
    /**
     * @param  list<string>  $changes
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $original,
        public readonly string $normalized,
        public readonly string $displayValue,
        public readonly bool $isValid,
        public readonly array $changes = [],
        public readonly array $warnings = [],
        public readonly ?string $failureCode = null,
    ) {}

    /**
     * @return array{
     *   original: string,
     *   normalized: string,
     *   display_value: string,
     *   is_valid: bool,
     *   changes: list<string>,
     *   warnings: list<string>,
     *   failure_code: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'original' => $this->original,
            'normalized' => $this->normalized,
            'display_value' => $this->displayValue,
            'is_valid' => $this->isValid,
            'changes' => $this->changes,
            'warnings' => $this->warnings,
            'failure_code' => $this->failureCode,
        ];
    }
}
