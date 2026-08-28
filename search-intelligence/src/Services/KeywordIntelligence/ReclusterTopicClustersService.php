<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterAlias;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterResolverService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\TopicClusterMergeService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ReclusterTopicClustersResult;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClassificationVisibility;

/**
 * Full-domain Topic Cluster rebuild.
 *
 * "Tách lại cluster" wipes derived memberships for the site, then re-runs the
 * canonical resolver pipeline on ALL eligible keywords (current phrases).
 * Old cluster_key / canonical meta are NOT hard constraints.
 *
 * Pass 0 — ensure classifications for site keywords
 * Pass 0b — wipe derived cluster_key + site meta/aliases/DNA (full rebuild)
 * Pass 1 — canonicalize inventory (empty after wipe; kept for incremental paths)
 * Pass 2 — contiguous core containment against live inventory
 * Pass 3 — conservative semantic/similarity (high confidence only)
 * Pass 4 — self/root clusters (shortest-first; grows inventory in-run)
 * Pass 4b — PRUNE AUTO SINGLETONS (member_count < 2, unless manual canonical or Focus Article)
 * Pass 4c — reconcile Focus Article orphans (attach or singleton Topic)
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
        private readonly PruneAutoSingletonClustersService $singletonPruner,
        private readonly ReconcileFocusArticleTopicsService $focusReconciler,
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
            'full_rebuild' => 1,
            'memberships_wiped' => 0,
            'manual_canonical_seeds' => 0,
            'auto_singletons_pruned' => 0,
            'singleton_keywords_unclustered' => 0,
            'focus_singletons_kept' => 0,
            'focus_orphans_before' => 0,
            'focus_attached_to_existing' => 0,
            'focus_singletons_created' => 0,
            'focus_orphans_after' => 0,
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

            // Full rebuild: wipe derived memberships; PRESERVE manual canonical seeds.
            $manualSeeds = $this->loadManualCanonicalSeeds($siteId);
            $metrics['memberships_wiped'] = $this->wipeDerivedClusterState($siteId, $work, $manualSeeds);
            foreach ($work as $i => $row) {
                $work[$i]['cluster_key'] = '';
            }

            /** @var array<string, true> $touchedClusters */
            $touchedClusters = [];

            // Seed inventory from persisted manual canonicals BEFORE Pass 2–4.
            $inventory = $this->inventoryFromManualSeeds($manualSeeds);
            foreach ($inventory as $item) {
                $touchedClusters[$item['cluster_key']] = true;
            }
            $metrics['manual_canonical_seeds'] = count($inventory);
            $metrics['clusters_before'] = max(
                $metrics['clusters_before'],
                (int) ($metrics['previously_clustered'] > 0 ? 1 : 0),
            );

            // PASS 1 skipped after wipe (manual seeds already loaded).
            // PASS 2 — core containment against manual seeds (+ growing inventory).
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

            // Residual merge among same-core clusters (never dissolve manual losers).
            foreach ($this->resolver->findMergeCandidates($siteId) as $candidate) {
                if (($candidate['confidence'] ?? '') !== 'high') {
                    $metrics['needs_review']++;

                    continue;
                }
                $loserKey = (string) $candidate['loser_key'];
                if ($this->isManualCluster($siteId, $loserKey)) {
                    // Prefer keeping the manual seed as survivor when possible.
                    if ($this->isManualCluster($siteId, (string) $candidate['survivor_key'])) {
                        continue;
                    }
                    try {
                        $this->mergeService->merge(
                            $siteId,
                            $loserKey,
                            (string) $candidate['survivor_key'],
                        );
                        $metrics['clusters_merged']++;
                        $touchedClusters[$loserKey] = true;

                        continue;
                    } catch (\Throwable) {
                        $metrics['failed']++;

                        continue;
                    }
                }
                try {
                    $this->mergeService->merge(
                        $siteId,
                        (string) $candidate['survivor_key'],
                        $loserKey,
                    );
                    $metrics['clusters_merged']++;
                    $touchedClusters[(string) $candidate['survivor_key']] = true;
                } catch (\Throwable) {
                    $metrics['failed']++;
                }
            }

            // Refresh assignment snapshot after merges.
            $finalRows = $this->loadEligibleRows($siteId);
            foreach ($finalRows as $row) {
                if (isset($excludedIds[$row['keyword_id']])) {
                    continue;
                }
                if ($row['cluster_key'] !== '') {
                    $touchedClusters[$row['cluster_key']] = true;
                }
            }

            // PASS 4b — prune AUTO singletons before DNA (persistence must stay clean).
            // Focus-Article singletons are kept (invariant).
            $pruneStats = $this->singletonPruner->prune($siteId, $touchedClusters);
            $metrics['auto_singletons_pruned'] = $pruneStats['pruned'];
            $metrics['singleton_keywords_unclustered'] = $pruneStats['keywords_unclustered'];
            $metrics['focus_singletons_kept'] = (int) ($pruneStats['focus_singletons_kept'] ?? 0);

            // PASS 4c — Focus Article keywords must never remain Topic=NULL.
            $focusStats = $this->focusReconciler->reconcile($siteId);
            $metrics['focus_orphans_before'] = (int) ($focusStats['orphans_before'] ?? 0);
            $metrics['focus_attached_to_existing'] = (int) ($focusStats['attached_to_existing'] ?? 0);
            $metrics['focus_singletons_created'] = (int) ($focusStats['singletons_created'] ?? 0);
            $metrics['focus_orphans_after'] = (int) ($focusStats['orphans_after'] ?? 0);

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
            TopicClusterDirtyState::clear($siteId);

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

        $existing = SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->where('cluster_key', $clusterKey)
            ->first();
        if ($existing instanceof SeoTopicClusterMeta && $existing->isManual()) {
            foreach ($phrases as $phrase) {
                $this->resolver->recordAlias($siteId, $clusterKey, $phrase);
            }

            return (string) $existing->canonical_phrase;
        }

        $normalized = $this->phraseResolver->normalizedKey($canonical);
        $payload = [
            'canonical_phrase' => $canonical,
            'normalized_canonical' => $normalized,
            'confidence' => 'high',
            'needs_review' => false,
        ];
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_topic_cluster_meta', 'canonical_source')) {
            $payload['canonical_source'] = SeoTopicClusterMeta::SOURCE_AUTO;
        }

        SeoTopicClusterMeta::query()->updateOrCreate(
            ['site_id' => $siteId, 'cluster_key' => $clusterKey],
            $payload,
        );
        foreach ($phrases as $phrase) {
            $this->resolver->recordAlias($siteId, $clusterKey, $phrase);
        }
        $this->resolver->recordAlias($siteId, $clusterKey, $canonical);

        return $canonical;
    }

    /**
     * @return list<array{cluster_key: string, canonical_phrase: string}>
     */
    private function loadManualCanonicalSeeds(int $siteId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta')) {
            return [];
        }
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_topic_cluster_meta', 'canonical_source')) {
            return [];
        }

        return SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->where('canonical_source', SeoTopicClusterMeta::SOURCE_MANUAL)
            ->get(['cluster_key', 'canonical_phrase'])
            ->map(static fn (SeoTopicClusterMeta $m): array => [
                'cluster_key' => (string) $m->cluster_key,
                'canonical_phrase' => (string) $m->canonical_phrase,
            ])
            ->filter(static fn (array $r): bool => $r['cluster_key'] !== '' && $r['canonical_phrase'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{cluster_key: string, canonical_phrase: string}>  $manualSeeds
     * @return list<array{cluster_key: string, canonical_phrase: string, normalized: string, tokens: list<string>, token_count: int, keyword_count: int}>
     */
    private function inventoryFromManualSeeds(array $manualSeeds): array
    {
        $out = [];
        foreach ($manualSeeds as $seed) {
            $out[] = $this->inventoryEntry($seed['cluster_key'], $seed['canonical_phrase'], 1);
        }

        return $out;
    }

    private function isManualCluster(int $siteId, string $clusterKey): bool
    {
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_topic_cluster_meta', 'canonical_source')) {
            return false;
        }

        $meta = SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->where('cluster_key', $clusterKey)
            ->first();

        return $meta instanceof SeoTopicClusterMeta && $meta->isManual();
    }

    /**
     * Remove derived cluster assignments for a full rebuild.
     * Preserves manual canonical meta rows (user-authored seeds).
     *
     * @param  list<array{keyword_id: int, phrase: string, cluster_key: string}>  $work
     * @param  list<array{cluster_key: string, canonical_phrase: string}>  $manualSeeds
     */
    private function wipeDerivedClusterState(int $siteId, array $work, array $manualSeeds = []): int
    {
        $ids = array_values(array_unique(array_map(
            static fn (array $row): int => $row['keyword_id'],
            $work,
        )));
        if ($ids === []) {
            return 0;
        }

        $protectedKeys = array_values(array_unique(array_map(
            static fn (array $s): string => $s['cluster_key'],
            $manualSeeds,
        )));

        $wiped = 0;
        DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $ids, $protectedKeys, &$wiped): void {
            $wiped = SeoKeywordClassification::query()
                ->whereIn('keyword_id', $ids)
                ->whereNotNull('cluster_key')
                ->where('cluster_key', '!=', '')
                ->count();

            SeoKeywordClassification::query()
                ->whereIn('keyword_id', $ids)
                ->update(['cluster_key' => null]);

            if (Schema::connection('omi_seo_ai')->hasTable('seo_keyword_dna')) {
                SeoKeywordDna::query()
                    ->where('site_id', $siteId)
                    ->whereIn('keyword_id', $ids)
                    ->delete();
            }

            if (Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta')) {
                if ($protectedKeys !== []
                    && Schema::connection('omi_seo_ai')->hasColumn('seo_topic_cluster_meta', 'canonical_source')
                ) {
                    SeoTopicClusterMeta::query()
                        ->where('site_id', $siteId)
                        ->where(function ($q) use ($protectedKeys): void {
                            $q->whereNotIn('cluster_key', $protectedKeys)
                                ->orWhere('canonical_source', '!=', SeoTopicClusterMeta::SOURCE_MANUAL)
                                ->orWhereNull('canonical_source');
                        })
                        ->delete();
                } else {
                    SeoTopicClusterMeta::query()->where('site_id', $siteId)->delete();
                }
            }

            if (Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_aliases')) {
                $aliasQuery = SeoTopicClusterAlias::query()->where('site_id', $siteId);
                if ($protectedKeys !== []) {
                    $aliasQuery->whereNotIn('cluster_key', $protectedKeys);
                }
                $aliasQuery->delete();
            }
        });

        return $wiped;
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
            ->where(function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('meta_key', self::META_MANUAL_EXCLUDE)
                        ->where('meta_value', '1');
                })->orWhere(function ($inner): void {
                    $inner->where('meta_key', \Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey::SeoHidden->value)
                        ->where('meta_value', '1');
                });
            })
            ->pluck('keyword_id')
            ->all();

        $out = [];
        foreach ($ids as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }
}
