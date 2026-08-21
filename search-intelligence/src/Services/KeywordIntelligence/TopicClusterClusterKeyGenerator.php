<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;

final class TopicClusterClusterKeyGenerator
{
    public function __construct(
        private readonly KeywordNormalizer $normalizer,
        private readonly KeywordClusterQuery $clusters,
    ) {}

    /**
     * @param  list<int>  $sortedKeywordIds
     * @param  array<string, true>  $reservedKeys
     */
    public function generate(
        int $siteId,
        string $representativeLabel,
        array $sortedKeywordIds,
        array $reservedKeys = [],
    ): string {
        sort($sortedKeywordIds, SORT_NUMERIC);
        $base = $this->buildBaseKey($representativeLabel, $sortedKeywordIds);

        $key = $this->resolveUniqueKey($siteId, $base, $sortedKeywordIds, $reservedKeys);
        if ($key !== null) {
            return $key;
        }

        $disambiguator = substr(hash('sha256', implode(',', $sortedKeywordIds)), 6, 4);
        $candidate = $base.'_'.$disambiguator;
        $key = $this->resolveUniqueKey($siteId, $candidate, $sortedKeywordIds, $reservedKeys);
        if ($key !== null) {
            return $key;
        }

        $final = $base.'_'.substr(hash('sha256', $base.'|'.implode(',', $sortedKeywordIds)), 0, 8);
        if (! isset($reservedKeys[$final]) && ! $this->clusters->clusterExists($final)) {
            return $final;
        }

        return $final.'_'.substr(hash('sha256', 'batch|'.implode(',', $sortedKeywordIds)), 0, 4);
    }

    /**
     * @param  list<int>  $sortedKeywordIds
     * @param  array<string, true>  $reservedKeys
     */
    private function resolveUniqueKey(
        int $siteId,
        string $candidate,
        array $sortedKeywordIds,
        array $reservedKeys,
    ): ?string {
        if (isset($reservedKeys[$candidate])) {
            return null;
        }

        if (! $this->clusters->clusterExists($candidate)) {
            return $candidate;
        }

        $existingIds = $this->clusters->memberKeywordIds($siteId, $candidate);
        sort($existingIds, SORT_NUMERIC);

        return $existingIds === $sortedKeywordIds ? $candidate : null;
    }

    /**
     * @param  list<int>  $sortedKeywordIds
     */
    private function buildBaseKey(string $representativeLabel, array $sortedKeywordIds): string
    {
        $norm = $this->normalizer->normalize($representativeLabel);
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $norm['folded_text']) ?? '';
        $slug = trim((string) $slug, '_');
        if ($slug === '') {
            $slug = 'topic_cluster';
        }
        if (strlen($slug) > 48) {
            $slug = substr($slug, 0, 48);
            $slug = rtrim($slug, '_');
        }

        $suffix = substr(hash('sha256', implode(',', $sortedKeywordIds)), 0, 6);

        return $slug.'__'.$suffix;
    }
}
