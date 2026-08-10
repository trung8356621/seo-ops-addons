<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

/**
 * Map GSC query → keyword_ref.
 * Exact normalized match; near-duplicate với intent guard; manual mapping preserved.
 */
final class GscQueryKeywordMapper
{
    public const ALGORITHM_VERSION = '1.0.0';

    public function __construct(
        private readonly GscQueryNormalizationService $queryNormalizer,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $candidates  keyword_ref, normalized, manual?, site_id?
     * @return array{keyword_ref: ?string, match_type: string, confidence: float, preserved_manual: bool}
     */
    public function map(string $query, string $siteId, array $candidates): array
    {
        $analysis = $this->queryNormalizer->analyze($query);
        if (! $analysis->isValid) {
            return $this->result(null, 'invalid_query', 0.0, false);
        }

        $siteCandidates = array_values(array_filter(
            $candidates,
            static fn (array $c): bool => (string) ($c['site_id'] ?? $siteId) === $siteId,
        ));

        foreach ($siteCandidates as $candidate) {
            if (($candidate['manual'] ?? false) === true) {
                $normalized = (string) ($candidate['normalized'] ?? '');
                if ($normalized === $analysis->normalized) {
                    return $this->result(
                        (string) ($candidate['keyword_ref'] ?? ''),
                        'manual',
                        1.0,
                        true,
                    );
                }
            }
        }

        foreach ($siteCandidates as $candidate) {
            $normalized = (string) ($candidate['normalized'] ?? '');
            if ($normalized !== '' && $normalized === $analysis->normalized) {
                return $this->result(
                    (string) ($candidate['keyword_ref'] ?? ''),
                    'exact',
                    0.95,
                    false,
                );
            }
        }

        foreach ($siteCandidates as $candidate) {
            if (($candidate['manual'] ?? false) === true) {
                continue;
            }

            $normalized = (string) ($candidate['normalized'] ?? '');
            if ($normalized === '') {
                continue;
            }

            if ($this->queryNormalizer->isNearDuplicate($analysis->normalized, $normalized)) {
                return $this->result(
                    (string) ($candidate['keyword_ref'] ?? ''),
                    'near',
                    0.7,
                    false,
                );
            }
        }

        return $this->result(null, 'unmapped', 0.0, false);
    }

    /**
     * @return array{keyword_ref: ?string, match_type: string, confidence: float, preserved_manual: bool, algorithm_version: string}
     */
    private function result(?string $keywordRef, string $matchType, float $confidence, bool $preservedManual): array
    {
        $ref = $keywordRef !== null && trim($keywordRef) !== '' ? trim($keywordRef) : null;

        return [
            'keyword_ref' => $ref,
            'match_type' => $matchType,
            'confidence' => $confidence,
            'preserved_manual' => $preservedManual,
            'algorithm_version' => self::ALGORITHM_VERSION,
        ];
    }
}
