<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscBrandQueryType;

/**
 * Phân loại brand / non-brand / mixed / unknown cho GSC query.
 */
final class GscBrandQueryClassifier
{
    public const ALGORITHM_VERSION = '1.0.0';

    public function __construct(
        private readonly GscQueryNormalizationService $queryNormalizer,
    ) {}

    /**
     * @param  list<string>  $brandTerms  override; empty → config
     */
    public function classify(string $query, array $brandTerms = []): GscBrandQueryType
    {
        $normalized = $this->queryNormalizer->normalize($query);
        if ($normalized === '') {
            return GscBrandQueryType::Unknown;
        }

        $terms = $brandTerms !== [] ? $brandTerms : $this->configBrandTerms();
        if ($terms === []) {
            return GscBrandQueryType::Unknown;
        }

        $tokens = preg_split('/\s+/u', $normalized) ?: [];
        $brandHits = 0;
        $nonBrandHits = 0;

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            $isBrand = false;
            foreach ($terms as $term) {
                $termNorm = $this->queryNormalizer->normalize($term);
                if ($termNorm === '') {
                    continue;
                }
                if ($token === $termNorm || str_contains($normalized, $termNorm)) {
                    $isBrand = true;
                    break;
                }
            }

            if ($isBrand) {
                $brandHits++;
            } else {
                $nonBrandHits++;
            }
        }

        if ($brandHits > 0 && $nonBrandHits === 0) {
            return GscBrandQueryType::Brand;
        }

        if ($brandHits === 0 && $nonBrandHits > 0) {
            return GscBrandQueryType::NonBrand;
        }

        if ($brandHits > 0 && $nonBrandHits > 0) {
            return GscBrandQueryType::Mixed;
        }

        return GscBrandQueryType::Unknown;
    }

    /** @return list<string> */
    private function configBrandTerms(): array
    {
        if (! function_exists('config')) {
            return [];
        }

        try {
            $value = config('seo-content-ai.gsc_intelligence.brand.terms', []);

            return is_array($value) ? array_values(array_filter(array_map('strval', $value))) : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
