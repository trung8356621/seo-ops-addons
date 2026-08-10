<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Data\GscQueryNormalizationResult;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordNormalizationService;

/**
 * Normalize GSC query — adapter KeywordNormalizationService, giữ dấu tiếng Việt, không dịch.
 */
final class GscQueryNormalizationService
{
    public const ALGORITHM_VERSION = '1.0.0';

    private const DEFAULT_MAX_LENGTH = 500;

    public function __construct(
        private readonly KeywordNormalizationService $keywordNormalizer,
    ) {}

    public function analyze(string $query): GscQueryNormalizationResult
    {
        $keywordResult = $this->keywordNormalizer->analyze($query);
        $identityParts = $this->identityParts($keywordResult->normalized);

        return new GscQueryNormalizationResult(
            original: $keywordResult->original,
            normalized: $keywordResult->normalized,
            displayValue: $keywordResult->displayValue,
            isValid: $keywordResult->isValid,
            changes: $keywordResult->changes,
            warnings: $keywordResult->warnings,
            failureCode: $keywordResult->failureCode,
            identityParts: $identityParts,
        );
    }

    public function normalize(string $query): string
    {
        return $this->keywordNormalizer->normalize($query);
    }

    public function displayQuery(string $query): string
    {
        return $this->keywordNormalizer->displayKeyword($query);
    }

    /**
     * @return list<string>
     */
    public function identityParts(string $normalizedQuery): array
    {
        if ($normalizedQuery === '') {
            return [];
        }

        $tokens = preg_split('/\s+/u', $normalizedQuery) ?: [];

        return array_values(array_filter($tokens, static fn (string $t): bool => $t !== ''));
    }

    public function identityHash(string $normalizedQuery): string
    {
        return hash('sha256', 'gsc-query:'.self::ALGORITHM_VERSION.':'.$normalizedQuery);
    }

    public function isNearDuplicate(string $aNormalized, string $bNormalized): bool
    {
        return $this->keywordNormalizer->isNearDuplicate($aNormalized, $bNormalized);
    }

    private function maxQueryLength(): int
    {
        if (! function_exists('config')) {
            return self::DEFAULT_MAX_LENGTH;
        }

        try {
            return (int) config('seo-content-ai.gsc_intelligence.normalization.max_query_length', self::DEFAULT_MAX_LENGTH);
        } catch (\Throwable) {
            return self::DEFAULT_MAX_LENGTH;
        }
    }
}
