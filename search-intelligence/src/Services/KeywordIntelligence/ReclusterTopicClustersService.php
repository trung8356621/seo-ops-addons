<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterResolverService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\TopicClusterMergeService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ReclusterTopicClustersResult;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClassificationVisibility;

/**
 * Full-domain Topic Cluster repair.
 *
 * Pass 1 — canonicalize existing inventory (promote/merge)
 * Pass 2 — attach by exact canonical-core token containment
 * Pass 3 — conservative semantic/similarity (resolver high confidence)
 * Pass 4 — self/root clusters for remaining valid keywords (shortest-first)
 * Pass 5 — rebuild DNA from final assignments
 */
final class ReclusterTopicClustersService
{
    public const META_MANUAL_EXCLUDE = 'cluster_manual_exclude';

    public function __construct(
        private readonly CanonicalClusterResolverService $resolver,
        private readonly CanonicalClusterPhraseResolver $phraseResolver,
        private readonly TopicClusterMergeService $mergeService,
        private readonly KeywordDnaService $dnaService,
        private readonly TopicClusterClusterKeyGenerator $keyGenerator,
        private readonly TopicClusterApplySideEffects $sideEffects,
        private readonly KeywordClusterEligibility $eligibility,
        private readonly KeywordClassificationService $classification,
    ) {}

    public function recluster(int $siteId): ReclusterTopicClustersResult
    {
        if ($siteId <= 0) {
            return ReclusterTopicClustersResult::failed('site_required');
        }

        $metrics = [
            'eligible_keywords' => 0,
            'clustered_before' => 0,
            'unclustered_before' => 0,
            'reassigned' => 0,
            'attached_by_core_match' => 0,
            'attached_by_contiguous_core' => 0,
            'attached_by_similarity' => 0,
            'attached_by_semantic' => 0,
            'reassigned_from_bad_match' => 0,
            'self_clusters_created' => 0,
            'remaining_unclustered' => 0,
            'dna_rebuilt' => 0,
            'clusters_merged' => 0,
            'canonical_changed' => 0,
            'manual_excluded' => 0,
            'clusters_before' => 0,
            'clusters_after' => 0,
            'needs_review' => 0,
            'failed' => 0,
            // BC aliases for UI banner / older consumers
            'previously_clustered' => 0,
            'previously_unclustered' => 0,
            'keywords_reassigned' => 0,
            'attached_to_existing' => 0,
            'processed' => 0,
            'keywords_processed' => 0,
            'canonical_phrases_changed' => 0,
            'dna_created' => 0,
            'dna_removed' => 0,
            'dna_unchanged' => 0,
            'classifications_ensured' => 0,
        ];

        try {
            if (! KeywordClassificationVisibility::tableReady()) {
                return ReclusterTopicClustersResult::failed('classifications_missing');
            }

            // Pass 0 — Dictionary keywords without classification never reach attach passes.
            $metrics['classifications_ensured'] = $this->ensureMissingClassifications($siteId);

            $rows = $this->loadEligibleRows($siteId);
            $metrics['eligible_keywords'] = count($rows);

            $excludedIds = $this->manualExcludedKeywordIds(
                array_map(static fn (array $r): int => $r['keyword_id'], $rows),
            );
            $metrics['manual_excluded'] = count($excludedIds);

            /** @var list<array{keyword_id: int, phrase: string, cluster_key: string}> $work */
            $work = [];
            foreach ($rows as $row) {
                if (isset($excludedIds[$row['keyword_id']])) {
                    continue;
                }
                if ($row['cluster_key'] !== '') {
                    $metrics['clustered_before']++;
                    $metrics['previously_clustered']++;
                } else {
                    $metrics['unclustered_before']++;
                    $metrics['previously_unclustered']++;
                }
                $work[] = $row;
            }

            /** @var array<string, true> $touchedClusters */
            $touchedClusters = [];

            // PASS 1 — canonicalize inventory first so shorter cores exist in-memory.
            $inventory = $this->pass1CanonicalizeInventory($siteId, $metrics, $touchedClusters);
            $metrics['clusters_before'] = max(
                $metrics['clusters_before'],
                count($inventory),
            );

            // PASS 2 — core containment against live inventory.
            $work = $this->pass2AttachByCoreContainment(
                $siteId,
                $work,
                $inventory,
                $metrics,
                $touchedClusters,
            );

            // PASS 3 — conservative semantic resolver for still-unmatched / wrong-cluster.
            $work = $this->pass3AttachBySimilarity(
                $siteId,
                $work,
                $inventory,
                $metrics,
                $touchedClusters,
            );

            // PASS 4 — self/root for remaining (shortest core first → reusable in-run).
            $this->pass4CreateSelfClusters(
                $siteId,
                $work,
                $inventory,
                $metrics,
                $touchedClusters,
            );

            // Residual merge among same-core clusters.
            foreach ($this->resolver->findMergeCandidates($siteId) as $candidate) {
                if (($candidate['confidence'] ?? '') !== 'high') {
                    $metrics['needs_review']++;

                    continue;
                }
                try {
                    $this->mergeService->merge(
                        $siteId,
                        (string) $candidate['survivor_key'],
                        (string) $candidate['loser_key'],
                    );
                    $metrics['clusters_merged']++;
                    $touchedClusters[(string) $candidate['survivor_key']] = true;
                } catch (\Throwable) {
                    $metrics['failed']++;
                }
            }

            // Refresh assignment snapshot after merges.
            $finalRows = $this->loadEligibleRows($siteId);
            $metrics['remaining_unclustered'] = 0;
            foreach ($finalRows as $row) {
                if (isset($excludedIds[$row['keyword_id']])) {
                    continue;
                }
                if ($row['cluster_key'] === '') {
                    $metrics['remaining_unclustered']++;
                } elseif ($row['cluster_key'] !== '') {
                    $touchedClusters[$row['cluster_key']] = true;
                }
            }

            // PASS 5 — DNA after stable assignments.
            foreach (array_keys($touchedClusters) as $clusterKey) {
                $canonical = $this->resolver->canonicalForCluster($siteId, $clusterKey) ?? '';
                if ($canonical === '') {
                    continue;
                }
                $metrics['dna_rebuilt'] += $this->dnaService->rebuildForCluster($siteId, $clusterKey, $canonical);
            }
            $metrics['dna_created'] = $metrics['dna_rebuilt'];

            $metrics['clusters_after'] = count($this->resolver->siteClusterInventory($siteId));
            $metrics['keywords_reassigned'] = $metrics['reassigned'];
            $metrics['attached_to_existing'] = $metrics['attached_by_core_match'] + $metrics['attached_by_similarity'];
            $metrics['attached_by_contiguous_core'] = $metrics['attached_by_core_match'];
            $metrics['attached_by_semantic'] = $metrics['attached_by_similarity'];
            $metrics['processed'] = $metrics['eligible_keywords'] - $metrics['manual_excluded'];
            $metrics['keywords_processed'] = $metrics['processed'];
            $metrics['canonical_phrases_changed'] = $metrics['canonical_changed'];

            $this->sideEffects->afterRecluster($siteId, $metrics);

            Log::info('topic_cluster.recluster.completed', [
                'site_id' => $siteId,
                ...$metrics,
            ]);

            return ReclusterTopicClustersResult::ok($metrics);
        } catch (\Throwable $exception) {
            Log::warning('topic_cluster.recluster.failed', [
                'site_id' => $siteId,
                'error' => $exception->getMessage(),
            ]);

            return ReclusterTopicClustersResult::failed($exception->getMessage());
        }
    }

    /**
     * Incremental attach for a single eligible keyword (no proposal engine).
     */
    public function assignKeyword(int $siteId, int $keywordId, string $phrase): ?string
    {
        if ($siteId <= 0 || $keywordId <= 0 || trim($phrase) === '') {
            return null;
        }

        if (isset($this->manualExcludedKeywordIds([$keywordId])[$keywordId])) {
            return null;
        }

        $inventory = $this->buildInventoryIndex($this->resolver->siteClusterInventory($siteId));
        $match = $this->findBestCoreMatch($phrase, $inventory);
        if ($match !== null) {
            $this->assignKeywordToCluster($keywordId, $match['cluster_key']);
            $this->touchClusterMeta($siteId, $match['cluster_key'], [$phrase], $match['canonical_phrase']);
            $this->dnaService->rebuildForKeyword($siteId, $keywordId, $match['cluster_key'], $phrase, $match['canonical_phrase']);

            return $match['cluster_key'];
        }

        $resolved = $this->resolver->resolveMatch($siteId, $this->phraseResolver->preferredClusterCore($phrase) ?: $phrase);
        if ($resolved !== null && $resolved->confidence === 'high') {
            $this->assignKeywordToCluster($keywordId, $resolved->clusterKey);
            $this->touchClusterMeta($siteId, $resolved->clusterKey, [$phrase], $resolved->canonicalPhrase);
            $this->dnaService->rebuildForKeyword($siteId, $keywordId, $resolved->clusterKey, $phrase, $resolved->canonicalPhrase);

            return $resolved->clusterKey;
        }

        $core = $this->phraseResolver->preferredClusterCore($phrase);
        if ($core === '') {
            return null;
        }

        $clusterKey = $this->keyGenerator->generate($siteId, $core, [$keywordId]);
        $this->assignKeywordToCluster($keywordId, $clusterKey);
        $this->forceCanonicalMeta($siteId, $clusterKey, $core, [$phrase]);
        $this->dnaService->rebuildForKeyword($siteId, $keywordId, $clusterKey, $phrase, $core);

        return $clusterKey;
    }

    /**
     * @param  array<string, int>  $metrics
     * @param  array<string, true>  $touchedClusters
     * @return list<array{cluster_key: string, canonical_phrase: string, normalized: string, tokens: list<string>, token_count: int}>
     */
    private function pass1CanonicalizeInventory(int $siteId, array &$metrics, array &$touchedClusters): array
    {
        $raw = $this->resolver->siteClusterInventory($siteId);
        $metrics['clusters_before'] = count($raw);

        foreach ($raw as $item) {
            $clusterKey = (string) $item['cluster_key'];
            $members = $item['member_phrases'];
            $preferred = $this->preferredCanonicalFromMembers($members);
            if ($preferred === '') {
                continue;
            }

            $existing = (string) $item['canonical_phrase'];
            if ($this->phraseResolver->normalizedKey($existing) === $this->phraseResolver->normalizedKey($preferred)) {
                $touchedClusters[$clusterKey] = true;

                continue;
            }

            if ($this->phraseResolver->shouldPromoteCanonical($existing, $preferred)
                || $this->phraseResolver->containsCanonicalCore($existing !== '' ? $existing : ($members[0] ?? ''), $preferred)
                || $existing === ''
            ) {
                $this->forceCanonicalMeta($siteId, $clusterKey, $preferred, $members);
                $metrics['canonical_changed']++;
                $touchedClusters[$clusterKey] = true;
            }
        }

        foreach ($this->resolver->findMergeCandidates($siteId) as $candidate) {
            if (($candidate['confidence'] ?? '') !== 'high') {
                continue;
            }
            try {
                $this->mergeService->merge(
                    $siteId,
                    (string) $candidate['survivor_key'],
                    (string) $candidate['loser_key'],
                );
                $metrics['clusters_merged']++;
                $touchedClusters[(string) $candidate['survivor_key']] = true;
            } catch (\Throwable) {
                $metrics['failed']++;
            }
        }

        return $this->buildInventoryIndex($this->resolver->siteClusterInventory($siteId));
    }

    /**
     * @param  list<array{keyword_id: int, phrase: string, cluster_key: string}>  $work
     * @param  list<array{cluster_key: string, canonical_phrase: string, normalized: string, tokens: list<string>, token_count: int}>  $inventory
     * @param  array<string, int>  $metrics
     * @param  array<string, true>  $touchedClusters
     * @return list<array{keyword_id: int, phrase: string, cluster_key: string}>
     */
    private function pass2AttachByCoreContainment(
        int $siteId,
        array $work,
        array &$inventory,
        array &$metrics,
        array &$touchedClusters,
    ): array {
        /** @var array<string, array{cluster_key: string, canonical_phrase: string, normalized: string, tokens: list<string>, token_count: int, keyword_count: int}> $byKey */
        $byKey = [];
        foreach ($inventory as $item) {
            $byKey[$item['cluster_key']] = $item;
        }

        $out = [];
        foreach ($work as $row) {
            $best = $this->findBestCoreMatch($row['phrase'], $inventory);
            if ($best !== null) {
                if ($row['cluster_key'] === $best['cluster_key']) {
                    $out[] = $row;

                    continue;
                }

                $previous = $row['cluster_key'];
                $this->assignKeywordToCluster($row['keyword_id'], $best['cluster_key']);
                $this->resolver->recordAlias($siteId, $best['cluster_key'], $row['phrase']);
                $metrics['attached_by_core_match']++;
                if ($previous !== '') {
                    $metrics['reassigned']++;
                    if (! $this->membershipValidForCanonical($row['phrase'], $byKey[$previous]['canonical_phrase'] ?? '')) {
                        $metrics['reassigned_from_bad_match']++;
                    }
                    $touchedClusters[$previous] = true;
                }
                $touchedClusters[$best['cluster_key']] = true;
                $out[] = [
                    'keyword_id' => $row['keyword_id'],
                    'phrase' => $row['phrase'],
                    'cluster_key' => $best['cluster_key'],
                ];

                continue;
            }

            // Stale polluted membership: keep only if still valid under contiguous rules.
            if ($row['cluster_key'] !== '') {
                $current = $byKey[$row['cluster_key']] ?? null;
                $canonical = is_array($current) ? (string) $current['canonical_phrase'] : '';
                if ($canonical === '' || ! $this->membershipValidForCanonical($row['phrase'], $canonical)) {
                    SeoKeywordClassification::query()
                        ->where('keyword_id', $row['keyword_id'])
                        ->update(['cluster_key' => null]);
                    $metrics['reassigned']++;
                    $metrics['reassigned_from_bad_match']++;
                    $touchedClusters[$row['cluster_key']] = true;
                    $out[] = [
                        'keyword_id' => $row['keyword_id'],
                        'phrase' => $row['phrase'],
                        'cluster_key' => '',
                    ];

                    continue;
                }
            }

            $out[] = $row;
        }

        return $out;
    }

    private function membershipValidForCanonical(string $phrase, string $canonical): bool
    {
        if ($canonical === '') {
            return false;
        }

        if ($this->phraseResolver->normalizedKey(
            $this->phraseResolver->preferredClusterCore($phrase) ?: $phrase,
        ) === $this->phraseResolver->normalizedKey($canonical)) {
            return true;
        }

        if ($this->phraseResolver->isBoilerplateSuperset($phrase, $canonical)) {
            return true;
        }

        return $this->phraseResolver->containsCanonicalCore($phrase, $canonical)
            && ! $this->phraseResolver->isGenericSingletonCore(
                $this->phraseResolver->significantTokens($canonical),
            );
    }

    private function pass3AttachBySimilarity(
        int $siteId,
        array $work,
        array &$inventory,
        array &$metrics,
        array &$touchedClusters,
    ): array {
        $out = [];
        foreach ($work as $row) {
            if ($row['cluster_key'] !== '') {
                $out[] = $row;

                continue;
            }

            $core = $this->phraseResolver->preferredClusterCore($row['phrase']) ?: $row['phrase'];
            $match = $this->resolver->resolveMatch($siteId, $core);
            if ($match === null || $match->confidence !== 'high') {
                $out[] = $row;

                continue;
            }

            // Alias-only / loose overlap is insufficient — require contiguous core, boilerplate, or exact core.
            if (! $this->membershipValidForCanonical($row['phrase'], $match->canonicalPhrase)) {
                $out[] = $row;

                continue;
            }

            $best = $this->findBestCoreMatch($row['phrase'], $inventory);
            $clusterKey = $best['cluster_key'] ?? $match->clusterKey;

            $this->assignKeywordToCluster($row['keyword_id'], $clusterKey);
            $this->resolver->recordAlias($siteId, $clusterKey, $row['phrase']);
            $metrics['attached_by_similarity']++;
            $touchedClusters[$clusterKey] = true;
            $out[] = [
                'keyword_id' => $row['keyword_id'],
                'phrase' => $row['phrase'],
                'cluster_key' => $clusterKey,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{keyword_id: int, phrase: string, cluster_key: string}>  $work
     * @param  list<array{cluster_key: string, canonical_phrase: string, normalized: string, tokens: list<string>, token_count: int}>  $inventory
     * @param  array<string, int>  $metrics
     * @param  array<string, true>  $touchedClusters
     */
    private function pass4CreateSelfClusters(
        int $siteId,
        array $work,
        array &$inventory,
        array &$metrics,
        array &$touchedClusters,
    ): void {
        $pending = array_values(array_filter(
            $work,
            static fn (array $row): bool => $row['cluster_key'] === '',
        ));

        usort($pending, function (array $a, array $b): int {
            $aCore = $this->phraseResolver->preferredClusterCore($a['phrase']);
            $bCore = $this->phraseResolver->preferredClusterCore($b['phrase']);
            $aLen = count($this->phraseResolver->significantTokens($aCore !== '' ? $aCore : $a['phrase']));
            $bLen = count($this->phraseResolver->significantTokens($bCore !== '' ? $bCore : $b['phrase']));

            return $aLen <=> $bLen ?: strlen($aCore) <=> strlen($bCore);
        });

        foreach ($pending as $row) {
            // Re-check containment against inventory grown in this pass.
            $best = $this->findBestCoreMatch($row['phrase'], $inventory);
            if ($best !== null) {
                $this->assignKeywordToCluster($row['keyword_id'], $best['cluster_key']);
                $this->resolver->recordAlias($siteId, $best['cluster_key'], $row['phrase']);
                $metrics['attached_by_core_match']++;
                $touchedClusters[$best['cluster_key']] = true;

                continue;
            }

            $core = $this->phraseResolver->preferredClusterCore($row['phrase']);
            if ($core === '') {
                $metrics['needs_review']++;

                continue;
            }

            // Exact canonical already present (same normalized core, different wording).
            foreach ($inventory as $item) {
                if ($item['normalized'] === $this->phraseResolver->normalizedKey($core)
                    && $this->phraseResolver->intentCompatible($row['phrase'], $item['canonical_phrase'])
                ) {
                    $this->assignKeywordToCluster($row['keyword_id'], $item['cluster_key']);
                    $this->resolver->recordAlias($siteId, $item['cluster_key'], $row['phrase']);
                    $metrics['attached_by_core_match']++;
                    $touchedClusters[$item['cluster_key']] = true;

                    continue 2;
                }
            }

            $clusterKey = $this->keyGenerator->generate($siteId, $core, [$row['keyword_id']]);
            $this->assignKeywordToCluster($row['keyword_id'], $clusterKey);
            $this->forceCanonicalMeta($siteId, $clusterKey, $core, [$row['phrase']]);
            $metrics['self_clusters_created']++;
            $touchedClusters[$clusterKey] = true;

            $inventory[] = $this->inventoryEntry($clusterKey, $core, 1);
        }
    }

    /**
     * @param  list<array{cluster_key: string, canonical_phrase: string, keyword_count: int, member_phrases: list<string>}>  $raw
     * @return list<array{cluster_key: string, canonical_phrase: string, normalized: string, tokens: list<string>, token_count: int, keyword_count: int}>
     */
    private function buildInventoryIndex(array $raw): array
    {
        $out = [];
        foreach ($raw as $item) {
            $canonical = (string) ($item['canonical_phrase'] ?? '');
            if ($canonical === '') {
                continue;
            }
            $out[] = $this->inventoryEntry(
                (string) $item['cluster_key'],
                $canonical,
                (int) ($item['keyword_count'] ?? 0),
            );
        }

        return $out;
    }

    /**
     * @return array{cluster_key: string, canonical_phrase: string, normalized: string, tokens: list<string>, token_count: int, keyword_count: int}
     */
    private function inventoryEntry(string $clusterKey, string $canonical, int $keywordCount = 1): array
    {
        $tokens = $this->phraseResolver->significantTokens($canonical);

        return [
            'cluster_key' => $clusterKey,
            'canonical_phrase' => $canonical,
            'normalized' => $this->phraseResolver->normalizedKey($canonical),
            'tokens' => $tokens,
            'token_count' => count($tokens),
            'keyword_count' => max(1, $keywordCount),
        ];
    }

    /**
     * Most specific intent-compatible canonical whose tokens are contained in the keyword.
     *
     * @param  list<array{cluster_key: string, canonical_phrase: string, normalized: string, tokens: list<string>, token_count: int, keyword_count: int}>  $inventory
     * @return array{cluster_key: string, canonical_phrase: string, normalized: string, tokens: list<string>, token_count: int, keyword_count: int}|null
     */
    private function findBestCoreMatch(string $phrase, array $inventory): ?array
    {
        $best = null;
        $bestScore = -1;

        foreach ($inventory as $item) {
            if ($item['token_count'] <= 0) {
                continue;
            }
            if ($this->phraseResolver->isGenericSingletonCore($item['tokens'])) {
                continue;
            }
            if (! $this->phraseResolver->containsCanonicalCore($phrase, $item['canonical_phrase'])) {
                continue;
            }

            // Prefer more specific core, then larger cluster, then stable key.
            $score = ($item['token_count'] * 1_000_000)
                + (min(9999, $item['keyword_count']) * 100)
                + (100 - min(99, strlen($item['cluster_key'])));

            if ($score > $bestScore
                || ($score === $bestScore && $best !== null && $item['cluster_key'] < $best['cluster_key'])
            ) {
                $best = $item;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $members
     */
    private function preferredCanonicalFromMembers(array $members): string
    {
        $cores = [];
        foreach ($members as $phrase) {
            $core = $this->phraseResolver->preferredClusterCore($phrase);
            if ($core !== '') {
                $cores[] = $core;
            }
        }

        if ($cores === []) {
            return $this->phraseResolver->pickCanonicalFromMembers($members);
        }

        // Shortest normalized service/product core among members.
        usort($cores, function (string $a, string $b): int {
            $aN = $this->phraseResolver->normalizedKey($a);
            $bN = $this->phraseResolver->normalizedKey($b);

            return mb_strlen($aN) <=> mb_strlen($bN) ?: strcmp($aN, $bN);
        });

        return $cores[0];
    }

    private function assignKeywordToCluster(int $keywordId, string $clusterKey): void
    {
        SeoKeywordClassification::query()
            ->where('keyword_id', $keywordId)
            ->update(['cluster_key' => $clusterKey]);
    }

    /**
     * @param  list<string>  $phrases
     */
    private function touchClusterMeta(int $siteId, string $clusterKey, array $phrases, string $canonical): void
    {
        $this->forceCanonicalMeta($siteId, $clusterKey, $canonical, $phrases);
    }

    /**
     * @param  list<string>  $phrases
     */
    private function forceCanonicalMeta(int $siteId, string $clusterKey, string $canonical, array $phrases): string
    {
        if (! $this->resolver->tablesReady()) {
            return $canonical;
        }

        $normalized = $this->phraseResolver->normalizedKey($canonical);
        SeoTopicClusterMeta::query()->updateOrCreate(
            ['site_id' => $siteId, 'cluster_key' => $clusterKey],
            [
                'canonical_phrase' => $canonical,
                'normalized_canonical' => $normalized,
                'confidence' => 'high',
                'needs_review' => false,
            ],
        );
        foreach ($phrases as $phrase) {
            $this->resolver->recordAlias($siteId, $clusterKey, $phrase);
        }
        $this->resolver->recordAlias($siteId, $clusterKey, $canonical);

        return $canonical;
    }

    /**
     * Create missing classification rows for site-scoped keywords (siteId=0 skips incremental assign).
     */
    private function ensureMissingClassifications(int $siteId): int
    {
        $keywordIds = KeywordClusterSiteScope::keywordIds($siteId);
        if ($keywordIds === []) {
            return 0;
        }

        $existing = SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $have = array_fill_keys($existing, true);
        $missing = [];
        foreach ($keywordIds as $id) {
            if (! isset($have[$id])) {
                $missing[] = $id;
            }
        }

        if ($missing === []) {
            return 0;
        }

        $ensured = 0;
        foreach (array_chunk($missing, 200) as $chunk) {
            $keywords = Keyword::query()->whereIn('id', $chunk)->get();
            foreach ($keywords as $keyword) {
                if (! $keyword instanceof Keyword) {
                    continue;
                }
                // siteId=0 → classify only; Pass 2–4 handle attach in this same run.
                if ($this->classification->classifyOne($keyword, 0)) {
                    $ensured++;
                }
            }
        }

        return $ensured;
    }

    /**
     * @return list<array{keyword_id: int, phrase: string, cluster_key: string}>
     */
    private function loadEligibleRows(int $siteId): array
    {
        $keywordIds = KeywordClusterSiteScope::keywordIds($siteId);
        if ($keywordIds === []) {
            return [];
        }

        $rows = SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->with(['keyword:id,phrase'])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if (! $row instanceof SeoKeywordClassification) {
                continue;
            }
            if (! $this->eligibility->isSeoEligible($row)) {
                continue;
            }
            $phrase = trim((string) ($row->keyword?->phrase ?? $row->normalized_text ?? ''));
            if ($phrase === '') {
                continue;
            }
            $out[] = [
                'keyword_id' => (int) $row->keyword_id,
                'phrase' => $phrase,
                'cluster_key' => trim((string) ($row->cluster_key ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array<int, true>
     */
    private function manualExcludedKeywordIds(array $keywordIds): array
    {
        if ($keywordIds === [] || ! Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            return [];
        }

        $ids = DB::connection('omi_seo_ai')->table('keyword_meta')
            ->whereIn('keyword_id', $keywordIds)
            ->where('meta_key', self::META_MANUAL_EXCLUDE)
            ->where('meta_value', '1')
            ->pluck('keyword_id')
            ->all();

        $out = [];
        foreach ($ids as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }
}
