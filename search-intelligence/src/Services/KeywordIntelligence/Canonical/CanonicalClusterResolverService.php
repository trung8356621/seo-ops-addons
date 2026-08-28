<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterAlias;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\CanonicalClusterMatch;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterSiteScope;

final class CanonicalClusterResolverService
{
    public function __construct(
        private readonly CanonicalClusterPhraseResolver $phraseResolver,
        private readonly KeywordClusterQuery $clusterQuery,
    ) {}

    public function tablesReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta')
            && Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_aliases');
    }

    /**
     * Resolve an existing cluster match for a phrase within a site.
     */
    public function resolveMatch(int $siteId, string $phrase): ?CanonicalClusterMatch
    {
        if ($siteId <= 0 || trim($phrase) === '') {
            return null;
        }

        $canonical = $this->phraseResolver->deriveCorePhrase($phrase);
        if ($canonical === '') {
            return null;
        }

        $normalized = $this->phraseResolver->normalizedKey($canonical);

        if ($this->tablesReady()) {
            $alias = SeoTopicClusterAlias::query()
                ->where('site_id', $siteId)
                ->where('normalized_alias', $normalized)
                ->first();
            if ($alias instanceof SeoTopicClusterAlias) {
                $meta = $this->metaForKey($siteId, (string) $alias->cluster_key);

                return new CanonicalClusterMatch(
                    clusterKey: (string) $alias->cluster_key,
                    canonicalPhrase: $meta?->canonical_phrase ?? $canonical,
                    confidence: 'high',
                    needsReview: (bool) ($meta?->needs_review ?? false),
                    matchType: 'alias',
                );
            }

            $meta = SeoTopicClusterMeta::query()
                ->where('site_id', $siteId)
                ->where('normalized_canonical', $normalized)
                ->first();
            if ($meta instanceof SeoTopicClusterMeta) {
                return new CanonicalClusterMatch(
                    clusterKey: (string) $meta->cluster_key,
                    canonicalPhrase: (string) $meta->canonical_phrase,
                    confidence: (string) $meta->confidence,
                    needsReview: (bool) $meta->needs_review,
                    matchType: 'canonical',
                );
            }
        }

        return $this->bidirectionalClusterLookup($siteId, $phrase, $canonical);
    }

    /**
     * Upsert canonical meta + alias for a cluster.
     *
     * @param  list<string>  $memberPhrases
     */
    public function upsertClusterMeta(
        int $siteId,
        string $clusterKey,
        array $memberPhrases,
        string $confidence = 'high',
        bool $needsReview = false,
    ): string {
        if ($siteId <= 0 || trim($clusterKey) === '' || ! $this->tablesReady()) {
            return $this->phraseResolver->pickCanonicalFromMembers($memberPhrases);
        }

        $existing = $this->metaForKey($siteId, $clusterKey);
        if ($existing instanceof SeoTopicClusterMeta && $existing->isManual()) {
            foreach ($memberPhrases as $phrase) {
                $this->recordAlias($siteId, $clusterKey, $phrase);
            }

            return (string) $existing->canonical_phrase;
        }

        $canonical = $this->phraseResolver->pickCanonicalFromMembers($memberPhrases);
        if ($existing instanceof SeoTopicClusterMeta) {
            $existingCanonical = (string) $existing->canonical_phrase;
            if ($this->phraseResolver->shouldPromoteCanonical($existingCanonical, $canonical)) {
                $this->recordAlias($siteId, $clusterKey, $existingCanonical);
            } elseif ($this->phraseResolver->shouldPromoteCanonical($canonical, $existingCanonical)) {
                $this->recordAlias($siteId, $clusterKey, $canonical);
                $canonical = $existingCanonical;
            } else {
                $canonical = $existingCanonical;
            }
        }

        $normalized = $this->phraseResolver->normalizedKey($canonical);

        $payload = [
            'canonical_phrase' => $canonical,
            'normalized_canonical' => $normalized,
            'confidence' => $confidence,
            'needs_review' => $needsReview,
        ];
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_topic_cluster_meta', 'canonical_source')) {
            $payload['canonical_source'] = SeoTopicClusterMeta::SOURCE_AUTO;
        }

        SeoTopicClusterMeta::query()->updateOrCreate(
            ['site_id' => $siteId, 'cluster_key' => $clusterKey],
            $payload,
        );

        foreach ($memberPhrases as $phrase) {
            $this->recordAlias($siteId, $clusterKey, $phrase);
        }

        return $canonical;
    }

    public function recordAlias(int $siteId, string $clusterKey, string $phrase): void
    {
        if ($siteId <= 0 || trim($clusterKey) === '' || trim($phrase) === '' || ! $this->tablesReady()) {
            return;
        }

        $normalized = $this->phraseResolver->normalizedKey($phrase);

        SeoTopicClusterAlias::query()->updateOrCreate(
            ['site_id' => $siteId, 'normalized_alias' => $normalized],
            [
                'cluster_key' => $clusterKey,
                'alias_phrase' => trim($phrase),
            ],
        );
    }

    public function canonicalForCluster(int $siteId, string $clusterKey): ?string
    {
        $meta = $this->metaForKey($siteId, $clusterKey);
        if ($meta instanceof SeoTopicClusterMeta) {
            return (string) $meta->canonical_phrase;
        }

        $phrases = $this->memberPhrases($siteId, $clusterKey);

        return $phrases !== [] ? $this->phraseResolver->pickCanonicalFromMembers($phrases) : null;
    }

    /**
     * @return list<array{cluster_key: string, canonical_phrase: string, keyword_count: int, member_phrases: list<string>}>
     */
    public function siteClusterInventory(int $siteId): array
    {
        if ($siteId <= 0 || ! $this->clusterQuery->classificationsReady()) {
            return [];
        }

        $keywordIds = KeywordClusterSiteScope::keywordIds($siteId);
        if ($keywordIds === []) {
            return [];
        }

        $keys = SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->whereNotNull('cluster_key')
            ->where('cluster_key', '!=', '')
            ->distinct()
            ->pluck('cluster_key')
            ->map(static fn ($key): string => trim((string) $key))
            ->filter(static fn (string $key): bool => $key !== '')
            ->values()
            ->all();

        $inventory = [];
        foreach ($keys as $key) {
            $memberPhrases = $this->memberPhrases($siteId, $key);
            $canonical = $this->canonicalForCluster($siteId, $key)
                ?? $this->phraseResolver->pickCanonicalFromMembers($memberPhrases);

            $inventory[] = [
                'cluster_key' => $key,
                'canonical_phrase' => $canonical,
                'keyword_count' => count($memberPhrases),
                'member_phrases' => $memberPhrases,
            ];
        }

        return $inventory;
    }

    /**
     * Find merge pairs among existing clusters (bidirectional canonical repair).
     *
     * @return list<array{survivor_key: string, loser_key: string, confidence: string, reason: string}>
     */
    public function findMergeCandidates(int $siteId): array
    {
        $inventory = $this->siteClusterInventory($siteId);
        $pairs = [];
        $count = count($inventory);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $inventory[$i];
                $b = $inventory[$j];

                $merge = $this->evaluatePairMerge($a, $b);
                if ($merge !== null) {
                    $pairs[] = $merge;
                }
            }
        }

        return $pairs;
    }

    private function bidirectionalClusterLookup(int $siteId, string $phrase, string $canonical): ?CanonicalClusterMatch
    {
        $inventory = $this->siteClusterInventory($siteId);
        $normalized = $this->phraseResolver->normalizedKey($canonical);

        foreach ($inventory as $item) {
            $existingCanonical = (string) $item['canonical_phrase'];
            $existingNorm = $this->phraseResolver->normalizedKey($existingCanonical);

            if ($existingNorm === $normalized) {
                return new CanonicalClusterMatch(
                    clusterKey: (string) $item['cluster_key'],
                    canonicalPhrase: $existingCanonical,
                    confidence: 'high',
                    needsReview: false,
                    matchType: 'exact_canonical',
                );
            }

            if ($this->phraseResolver->isBoilerplateSuperset($phrase, $existingCanonical)) {
                return new CanonicalClusterMatch(
                    clusterKey: (string) $item['cluster_key'],
                    canonicalPhrase: $existingCanonical,
                    confidence: 'high',
                    needsReview: false,
                    matchType: 'superset_attach',
                );
            }

            // Contiguous core only — loose subsequence is intentionally excluded.
            if ($this->phraseResolver->containsCanonicalCore($phrase, $existingCanonical)
                && ! $this->phraseResolver->isGenericSingletonCore(
                    $this->phraseResolver->significantTokens($existingCanonical),
                )
            ) {
                return new CanonicalClusterMatch(
                    clusterKey: (string) $item['cluster_key'],
                    canonicalPhrase: $existingCanonical,
                    confidence: 'high',
                    needsReview: false,
                    matchType: 'core_containment',
                );
            }

            foreach ($item['member_phrases'] as $memberPhrase) {
                if ($this->phraseResolver->isBoilerplateSuperset($memberPhrase, $canonical)) {
                    return new CanonicalClusterMatch(
                        clusterKey: (string) $item['cluster_key'],
                        canonicalPhrase: $existingCanonical,
                        confidence: 'medium',
                        needsReview: true,
                        matchType: 'member_superset',
                    );
                }
            }
        }

        return null;
    }

    /**
     * @param  array{cluster_key: string, canonical_phrase: string, keyword_count: int, member_phrases: list<string>}  $a
     * @param  array{cluster_key: string, canonical_phrase: string, keyword_count: int, member_phrases: list<string>}  $b
     * @return array{survivor_key: string, loser_key: string, confidence: string, reason: string}|null
     */
    private function evaluatePairMerge(array $a, array $b): ?array
    {
        $aCanonical = (string) $a['canonical_phrase'];
        $bCanonical = (string) $b['canonical_phrase'];

        if ($this->phraseResolver->normalizedKey($aCanonical) === $this->phraseResolver->normalizedKey($bCanonical)) {
            $survivor = ((int) $a['keyword_count']) >= ((int) $b['keyword_count']) ? $a : $b;
            $loser = $survivor === $a ? $b : $a;

            return [
                'survivor_key' => (string) $survivor['cluster_key'],
                'loser_key' => (string) $loser['cluster_key'],
                'confidence' => 'high',
                'reason' => 'same_canonical',
            ];
        }

        if ($this->phraseResolver->isBoilerplateSuperset($aCanonical, $bCanonical)) {
            return [
                'survivor_key' => (string) $b['cluster_key'],
                'loser_key' => (string) $a['cluster_key'],
                'confidence' => 'high',
                'reason' => 'promote_shorter',
            ];
        }

        if ($this->phraseResolver->isBoilerplateSuperset($bCanonical, $aCanonical)) {
            return [
                'survivor_key' => (string) $a['cluster_key'],
                'loser_key' => (string) $b['cluster_key'],
                'confidence' => 'high',
                'reason' => 'promote_shorter',
            ];
        }

        // Core containment with modifiers (not glue-only) — prefer longer-token / more specific survivor label via shorter canonical.
        if ($this->phraseResolver->containsCanonicalCore($aCanonical, $bCanonical)
            && mb_strlen($this->phraseResolver->normalizedKey($bCanonical))
                < mb_strlen($this->phraseResolver->normalizedKey($aCanonical))
        ) {
            return [
                'survivor_key' => (string) $b['cluster_key'],
                'loser_key' => (string) $a['cluster_key'],
                'confidence' => 'high',
                'reason' => 'core_containment_promote',
            ];
        }

        if ($this->phraseResolver->containsCanonicalCore($bCanonical, $aCanonical)
            && mb_strlen($this->phraseResolver->normalizedKey($aCanonical))
                < mb_strlen($this->phraseResolver->normalizedKey($bCanonical))
        ) {
            return [
                'survivor_key' => (string) $a['cluster_key'],
                'loser_key' => (string) $b['cluster_key'],
                'confidence' => 'high',
                'reason' => 'core_containment_promote',
            ];
        }

        if (! $this->phraseResolver->intentCompatible($aCanonical, $bCanonical)) {
            return null;
        }

        return null;
    }

    private function metaForKey(int $siteId, string $clusterKey): ?SeoTopicClusterMeta
    {
        if (! $this->tablesReady()) {
            return null;
        }

        return SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->where('cluster_key', $clusterKey)
            ->first();
    }

    /**
     * @return list<string>
     */
    private function memberPhrases(int $siteId, string $clusterKey): array
    {
        $ids = $this->clusterQuery->memberKeywordIds($siteId, $clusterKey);
        if ($ids === []) {
            return [];
        }

        return Keyword::query()
            ->whereIn('id', $ids)
            ->orderBy('phrase')
            ->pluck('phrase')
            ->map(static fn ($p): string => trim((string) $p))
            ->filter(static fn (string $p): bool => $p !== '')
            ->values()
            ->all();
    }
}
