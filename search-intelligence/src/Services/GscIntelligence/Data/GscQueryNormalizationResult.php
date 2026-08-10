<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Data;

/**
 * Kết quả chuẩn hoá GSC query — giữ dấu tiếng Việt, không dịch.
 */
final class GscQueryNormalizationResult
{
    /**
     * @param  list<string>  $changes
     * @param  list<string>  $warnings
     * @param  list<string>  $identityParts
     */
    public function __construct(
        public readonly string $original,
        public readonly string $normalized,
        public readonly string $displayValue,
        public readonly bool $isValid,
        public readonly array $changes = [],
        public readonly array $warnings = [],
        public readonly ?string $failureCode = null,
        public readonly array $identityParts = [],
    ) {}

    /**
     * @return array<string, mixed>
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
            'identity_parts' => $this->identityParts,
        ];
    }
}
