<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterResolverService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClassificationVisibility;

/**
 * Enforces: every SEO-eligible keyword with ≥1 Focus Article must have a Topic (cluster_key).
 *
 * Runs after normal recluster + AUTO singleton prune. Prefer attaching to an existing
 * Topic; otherwise create a singleton Topic (allowed — Focus Article overrides min size 2).
 */
final class ReconcileFocusArticleTopicsService
{
    public function __construct(
        private readonly CanonicalClusterPhraseResolver $phraseResolver,
        private readonly CanonicalClusterResolverService $resolver,
        private readonly TopicClusterClusterKeyGenerator $keyGenerator,
        private readonly KeywordDnaService $dnaService,
        private readonly KeywordClusterEligibility $eligibility,
    ) {}

    /**
     * @return array{
     *     orphans_before: int,
     *     attached_to_existing: int,
     *     attached_by_shared_focus: int,
     *     attached_by_product_core: int,
     *     singletons_created: int,
     *     orphans_after: int
     * }
     */
    public function reconcile(int $siteId): array
    {
        $metrics = [
            'orphans_before' => 0,
            'attached_to_existing' => 0,
            'attached_by_shared_focus' => 0,
            'attached_by_product_core' => 0,
            'singletons_created' => 0,
            'orphans_after' => 0,
        ];

        if ($siteId <= 0 || ! KeywordClassificationVisibility::tableReady()) {
            return $metrics;
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_classifications')
            || ! Schema::connection('omi_seo_ai')->hasTable('keyword_meta')
        ) {
            return $metrics;
        }

        $orphans = $this->loadOrphanFocusKeywords($siteId);
        $metrics['orphans_before'] = count($orphans);

        $inventory = $this->buildInventory($siteId);
        $sharedFocusMap = $this->clusterKeysByFocusArticleId($siteId);

        foreach ($orphans as $row) {
            $keywordId = $row['keyword_id'];
            $phrase = $row['phrase'];
            $focusIds = $row['focus_article_ids'];

            // 1–2. Manual / existing inventory via product-core (intent-agnostic contiguous tokens).
            $productMatch = $this->findBestProductCoreMatch($phrase, $inventory);
            if ($productMatch !== null) {
                $this->attach($siteId, $keywordId, $phrase, $productMatch['cluster_key'], $productMatch['canonical_phrase']);
                $metrics['attached_by_product_core']++;
                $metrics['attached_to_existing']++;
                $this->bumpInventoryCount($inventory, $productMatch['cluster_key']);
                foreach ($focusIds as $articleId) {
                    $sharedFocusMap[$articleId][$productMatch['cluster_key']] = true;
                }

                continue;
            }

            // 3. High-confidence resolver match (existing clustering path).
            $core = $this->phraseResolver->preferredClusterCore($phrase) ?: $phrase;
            $resolved = $this->resolver->resolveMatch($siteId, $core);
            if ($resolved !== null
                && $resolved->confidence === 'high'
                && $this->membershipValidForCanonical($phrase, $resolved->canonicalPhrase)
            ) {
                $this->attach($siteId, $keywordId, $phrase, $resolved->clusterKey, $resolved->canonicalPhrase);
                $metrics['attached_to_existing']++;
                $this->bumpInventoryCount($inventory, $resolved->clusterKey);
                foreach ($focusIds as $articleId) {
                    $sharedFocusMap[$articleId][$resolved->clusterKey] = true;
                }

                continue;
            }

            // 4. Shared Focus Article with an already-clustered keyword.
            $sharedKey = $this->pickSharedFocusCluster($focusIds, $sharedFocusMap, $inventory);
            if ($sharedKey !== null) {
                $canonical = $this->canonicalForKey($siteId, $sharedKey, $inventory);
                $this->attach($siteId, $keywordId, $phrase, $sharedKey, $canonical !== '' ? $canonical : $phrase);
                $metrics['attached_by_shared_focus']++;
                $metrics['attached_to_existing']++;
                $this->bumpInventoryCount($inventory, $sharedKey);

                continue;
            }

            // 5–6. Singleton Topic (Focus Article invariant).
            $canonical = $core !== '' ? $core : $phrase;
            $clusterKey = $this->keyGenerator->generate($siteId, $canonical, [$keywordId]);
            $this->assignKeywordToCluster($keywordId, $clusterKey);
            $this->forceAutoCanonicalMeta($siteId, $clusterKey, $canonical, [$phrase]);
            $this->dnaService->rebuildForKeyword($siteId, $keywordId, $clusterKey, $phrase, $canonical);
            $metrics['singletons_created']++;
            $inventory[] = $this->inventoryEntry($clusterKey, $canonical, 1, false);
            foreach ($focusIds as $articleId) {
                $sharedFocusMap[$articleId][$clusterKey] = true;
            }
        }

        // Refresh inventory after orphan attach/singleton create, then upgrade
        // Focus keywords stuck on AUTO singletons when a stronger Topic exists
        // (e.g. service-intent self-cluster vs manual product Topic).
        $inventory = $this->buildInventory($siteId);
        $upgraded = $this->upgradeFocusAutoSingletons($siteId, $inventory);
        $metrics['attached_by_product_core'] += $upgraded;
        $metrics['attached_to_existing'] += $upgraded;

        $metrics['orphans_after'] = count($this->loadOrphanFocusKeywords($siteId));

        return $metrics;
    }

    /**
     * Re-home Focus Article keywords that Pass 4 parked on AUTO singletons when a
     * better product-core Topic (prefer manual / larger) exists.
     *
     * @param  list<array{cluster_key: string, canonical_phrase: string, normalized: string, tokens: list<string>, token_count: int, keyword_count: int, is_manual: bool}>  $inventory
     */
    private function upgradeFocusAutoSingletons(int $siteId, array &$inventory): int
    {
        $keywordIds = KeywordClusterSiteScope::keywordIds($siteId);
        $focusMap = $this->focusArticleIdsByKeyword($keywordIds);
        if ($focusMap === []) {
            return 0;
        }

        $rows = SeoKeywordClassification::query()
            ->whereIn('keyword_id', array_keys($focusMap))
            ->whereNotNull('cluster_key')
            ->where('cluster_key', '!=', '')
            ->with(['keyword:id,phrase'])
            ->get();

        $memberCounts = SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->whereNotNull('cluster_key')
            ->where('cluster_key', '!=', '')
            ->selectRaw('cluster_key, COUNT(*) as member_count')
            ->groupBy('cluster_key')
            ->pluck('member_count', 'cluster_key');

        $upgraded = 0;
        foreach ($rows as $row) {
            if (! $row instanceof SeoKeywordClassification || ! $this->eligibility->isSeoEligible($row)) {
                continue;
            }
            $currentKey = trim((string) ($row->cluster_key ?? ''));
            if ($currentKey === '' || (int) ($memberCounts[$currentKey] ?? 0) !== 1) {
                continue;
            }
            if ($this->isManualCluster($siteId, $currentKey)) {
                continue;
            }

            $phrase = trim((string) ($row->keyword?->phrase ?? $row->normalized_text ?? ''));
            if ($phrase === '') {
                continue;
            }

            $productMatch = $this->findBestProductCoreMatch($phrase, $inventory);
            if ($productMatch === null || $productMatch['cluster_key'] === $currentKey) {
                continue;
            }

            // Only upgrade toward manual or multi-member / more-specific topics.
            if (! $productMatch['is_manual'] && $productMatch['keyword_count'] < 2) {
                continue;
            }

            $this->attach(
                $siteId,
                (int) $row->keyword_id,
                $phrase,
                $productMatch['cluster_key'],
                $productMatch['canonical_phrase'],
            );
            $this->bumpInventoryCount($inventory, $productMatch['cluster_key']);
            app(TopicClusterDerivedCleanup::class)->purgeClusterArtifacts($siteId, $currentKey);
            unset($memberCounts[$currentKey]);
            $upgraded++;
        }

        return $upgraded;
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
     * @return list<array{keyword_id: int, phrase: string, focus_article_ids: list<int>}>
     */
    public function loadOrphanFocusKeywords(int $siteId): array
    {
        $keywordIds = KeywordClusterSiteScope::keywordIds($siteId);
        if ($keywordIds === []) {
            return [];
        }

        $focusMap = $this->focusArticleIdsByKeyword($keywordIds);
        if ($focusMap === []) {
            return [];
        }

        $rows = SeoKeywordClassification::query()
            ->whereIn('keyword_id', array_keys($focusMap))
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
            if (trim((string) ($row->cluster_key ?? '')) !== '') {
                continue;
            }
            $phrase = trim((string) ($row->keyword?->phrase ?? $row->normalized_text ?? ''));
            if ($phrase === '') {
                continue;
            }
            $kid = (int) $row->keyword_id;
            $out[] = [
                'keyword_id' => $kid,
                'phrase' => $phrase,
                'focus_article_ids' => $focusMap[$kid] ?? [],
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array<int, list<int>>
     */
    public function focusArticleIdsByKeyword(array $keywordIds): array
    {
        if ($keywordIds === [] || ! Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            return [];
        }

        $metas = DB::connection('omi_seo_ai')->table('keyword_meta')
            ->whereIn('keyword_id', $keywordIds)
            ->where('meta_key', KeywordMetaKey::MainArticleId->value)
            ->whereNotNull('meta_value')
            ->where('meta_value', '!=', '')
            ->get(['keyword_id', 'meta_value']);

        $out = [];
        foreach ($metas as $meta) {
            $kid = (int) ($meta->keyword_id ?? 0);
            $aid = (int) ($meta->meta_value ?? 0);
            if ($kid <= 0 || $aid <= 0) {
                continue;
            }
            $out[$kid][$aid] = $aid;
        }

        foreach ($out as $kid => $ids) {
            $out[$kid] = array_values($ids);
        }

        return $out;
    }

    /**
     * Contiguous product-core containment without service/product intent gate.
     * Avoids naive substring matching; still requires full token-phrase containment.
     *
     * @param  list<array{cluster_key: string, canonical_phrase: string, normalized: string, tokens: list<string>, token_count: int, keyword_count: int, is_manual: bool}>  $inventory
     * @return array{cluster_key: string, canonical_phrase: string, normalized: string, tokens: list<string>, token_count: int, keyword_count: int, is_manual: bool}|null
     */
    private function findBestProductCoreMatch(string $phrase, array $inventory): ?array
    {
        $keywordTokens = $this->phraseResolver->significantTokens($phrase);
        if ($keywordTokens === []) {
            return null;
        }

        $best = null;
        $bestScore = -1;

        foreach ($inventory as $item) {
            if ($item['token_count'] < 2) {
                continue;
            }
            if ($this->phraseResolver->isGenericSingletonCore($item['tokens'])) {
                continue;
            }
            if (! $this->phraseResolver->containsContiguousTokenPhrase($keywordTokens, $item['tokens'])) {
                continue;
            }

            $score = ($item['is_manual'] ? 10_000_000 : 0)
                + ($item['token_count'] * 1_000_000)
                + (min(9999, $item['keyword_count']) * 100);

            if ($score > $bestScore
                || ($score === $bestScore && $best !== null && $item['cluster_key'] < $best['cluster_key'])
            ) {
                $best = $item;
                $bestScore = $score;
            }
        }

        return $best;
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

    /**
     * @param  list<int>  $focusIds
     * @param  array<int, array<string, true>>  $sharedFocusMap
     * @param  list<array{cluster_key: string, canonical_phrase: string, normalized: string, tokens: list<string>, token_count: int, keyword_count: int, is_manual: bool}>  $inventory
     */
    private function pickSharedFocusCluster(array $focusIds, array $sharedFocusMap, array $inventory): ?string
    {
        /** @var array<string, int> $votes */
        $votes = [];
        foreach ($focusIds as $articleId) {
            foreach (array_keys($sharedFocusMap[$articleId] ?? []) as $clusterKey) {
                $votes[$clusterKey] = ($votes[$clusterKey] ?? 0) + 1;
            }
        }
        if ($votes === []) {
            return null;
        }

        $manualKeys = [];
        foreach ($inventory as $item) {
            if ($item['is_manual']) {
                $manualKeys[$item['cluster_key']] = true;
            }
        }

        uksort($votes, static function (string $a, string $b) use ($votes, $manualKeys): int {
            $manualCmp = ((isset($manualKeys[$b]) ? 1 : 0) <=> (isset($manualKeys[$a]) ? 1 : 0));
            if ($manualCmp !== 0) {
                return $manualCmp;
            }
            $countCmp = ($votes[$b] <=> $votes[$a]);
            if ($countCmp !== 0) {
                return $countCmp;
            }

            return strcmp($a, $b);
        });

        return array_key_first($votes);
    }

    /**
     * @return array<int, array<string, true>>
     */
    private function clusterKeysByFocusArticleId(int $siteId): array
    {
        $keywordIds = KeywordClusterSiteScope::keywordIds($siteId);
        if ($keywordIds === []) {
            return [];
        }

        $clusterByKeyword = SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->whereNotNull('cluster_key')
            ->where('cluster_key', '!=', '')
            ->pluck('cluster_key', 'keyword_id');

        $focusMap = $this->focusArticleIdsByKeyword($keywordIds);
        $out = [];
        foreach ($focusMap as $kid => $articleIds) {
            $clusterKey = trim((string) ($clusterByKeyword[$kid] ?? ''));
            if ($clusterKey === '') {
                continue;
            }
            foreach ($articleIds as $articleId) {
                $out[$articleId][$clusterKey] = true;
            }
        }

        return $out;
    }

    /**
     * @return list<array{cluster_key: string, canonical_phrase: string, normalized: string, tokens: list<string>, token_count: int, keyword_count: int, is_manual: bool}>
     */
    private function buildInventory(int $siteId): array
    {
        $raw = $this->resolver->siteClusterInventory($siteId);
        $manualKeys = [];
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_topic_cluster_meta', 'canonical_source')) {
            $manualKeys = SeoTopicClusterMeta::query()
                ->where('site_id', $siteId)
                ->where('canonical_source', SeoTopicClusterMeta::SOURCE_MANUAL)
                ->pluck('cluster_key')
                ->mapWithKeys(static fn ($k): array => [(string) $k => true])
                ->all();
        }

        $out = [];
        foreach ($raw as $item) {
            $canonical = (string) ($item['canonical_phrase'] ?? '');
            if ($canonical === '') {
                continue;
            }
            $key = (string) $item['cluster_key'];
            $out[] = $this->inventoryEntry(
                $key,
                $canonical,
                (int) ($item['keyword_count'] ?? 0),
                isset($manualKeys[$key]),
            );
        }

        return $out;
    }

    /**
     * @return array{cluster_key: string, canonical_phrase: string, normalized: string, tokens: list<string>, token_count: int, keyword_count: int, is_manual: bool}
     */
    private function inventoryEntry(string $clusterKey, string $canonical, int $keywordCount, bool $isManual): array
    {
        $tokens = $this->phraseResolver->significantTokens($canonical);

        return [
            'cluster_key' => $clusterKey,
            'canonical_phrase' => $canonical,
            'normalized' => $this->phraseResolver->normalizedKey($canonical),
            'tokens' => $tokens,
            'token_count' => count($tokens),
            'keyword_count' => max(1, $keywordCount),
            'is_manual' => $isManual,
        ];
    }

    /**
     * @param  list<array{cluster_key: string, canonical_phrase: string, normalized: string, tokens: list<string>, token_count: int, keyword_count: int, is_manual: bool}>  $inventory
     */
    private function bumpInventoryCount(array &$inventory, string $clusterKey): void
    {
        foreach ($inventory as $i => $item) {
            if ($item['cluster_key'] === $clusterKey) {
                $inventory[$i]['keyword_count']++;

                return;
            }
        }
    }

    /**
     * @param  list<array{cluster_key: string, canonical_phrase: string, normalized: string, tokens: list<string>, token_count: int, keyword_count: int, is_manual: bool}>  $inventory
     */
    private function canonicalForKey(int $siteId, string $clusterKey, array $inventory): string
    {
        foreach ($inventory as $item) {
            if ($item['cluster_key'] === $clusterKey) {
                return $item['canonical_phrase'];
            }
        }

        return (string) (SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->where('cluster_key', $clusterKey)
            ->value('canonical_phrase') ?? '');
    }

    private function attach(int $siteId, int $keywordId, string $phrase, string $clusterKey, string $canonical): void
    {
        $this->assignKeywordToCluster($keywordId, $clusterKey);
        $this->resolver->recordAlias($siteId, $clusterKey, $phrase);
        $this->dnaService->rebuildForKeyword($siteId, $keywordId, $clusterKey, $phrase, $canonical);
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
    private function forceAutoCanonicalMeta(int $siteId, string $clusterKey, string $canonical, array $phrases): void
    {
        if (! $this->resolver->tablesReady()) {
            return;
        }

        $existing = SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->where('cluster_key', $clusterKey)
            ->first();
        if ($existing instanceof SeoTopicClusterMeta && $existing->isManual()) {
            foreach ($phrases as $phrase) {
                $this->resolver->recordAlias($siteId, $clusterKey, $phrase);
            }

            return;
        }

        $payload = [
            'canonical_phrase' => $canonical,
            'normalized_canonical' => $this->phraseResolver->normalizedKey($canonical),
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
    }
}
