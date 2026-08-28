<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterResolverService;
use RuntimeException;

/**
 * User override of a cluster's canonical/core phrase (not keyword.phrase).
 */
final class UpdateClusterCanonicalService
{
    public const SOURCE_AUTO = 'auto';

    public const SOURCE_MANUAL = 'manual';

    public function __construct(
        private readonly CanonicalClusterPhraseResolver $phraseResolver,
        private readonly CanonicalClusterResolverService $resolver,
        private readonly KeywordDnaService $dnaService,
        private readonly KeywordClusterEligibility $eligibility,
        private readonly PruneAutoSingletonClustersService $singletonPruner,
    ) {}

    /**
     * @return array{
     *     changed: bool,
     *     cluster_key: string,
     *     canonical_phrase: string,
     *     previous_phrase: string,
     *     attached: int,
     *     detached: int
     * }
     */
    public function setManualCanonical(int $siteId, string $clusterKey, string $newPhrase): array
    {
        $clusterKey = trim($clusterKey);
        $phrase = trim(preg_replace('/\s+/u', ' ', $newPhrase) ?? $newPhrase);
        if ($siteId <= 0 || $clusterKey === '' || $phrase === '') {
            throw new RuntimeException('canonical_required');
        }
        if (mb_strlen($phrase) > 255) {
            throw new RuntimeException('canonical_too_long');
        }

        $previous = (string) ($this->resolver->canonicalForCluster($siteId, $clusterKey) ?? '');
        $normalized = $this->phraseResolver->normalizedKey($phrase);

        DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $clusterKey, $phrase, $normalized): void {
            $payload = [
                'canonical_phrase' => $phrase,
                'normalized_canonical' => $normalized,
                'confidence' => 'high',
                'needs_review' => false,
            ];
            if (Schema::connection('omi_seo_ai')->hasColumn('seo_topic_cluster_meta', 'canonical_source')) {
                $payload['canonical_source'] = self::SOURCE_MANUAL;
            }
            SeoTopicClusterMeta::query()->updateOrCreate(
                ['site_id' => $siteId, 'cluster_key' => $clusterKey],
                $payload,
            );
            $this->resolver->recordAlias($siteId, $clusterKey, $phrase);
        });

        $stats = $this->reevaluateMembershipForCanonical($siteId, $clusterKey, $phrase);
        TopicClusterDirtyState::mark($siteId, 'canonical_manual_edited');
        \Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpTopicalProfileStaleState::mark(
            $siteId,
            'canonical_manual_edited',
        );

        return [
            'changed' => $this->phraseResolver->normalizedKey($previous) !== $normalized,
            'cluster_key' => $clusterKey,
            'canonical_phrase' => $phrase,
            'previous_phrase' => $previous,
            'attached' => $stats['attached'],
            'detached' => $stats['detached'],
        ];
    }

    /**
     * Clear manual override; next recluster may pick auto canonical.
     *
     * @return array{cluster_key: string, canonical_phrase: string}
     */
    public function resetToAuto(int $siteId, string $clusterKey): array
    {
        $clusterKey = trim($clusterKey);
        if ($siteId <= 0 || $clusterKey === '') {
            throw new RuntimeException('cluster_required');
        }

        $meta = SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->where('cluster_key', $clusterKey)
            ->first();

        $members = $this->memberPhrases($siteId, $clusterKey);
        $auto = $this->phraseResolver->preferredClusterCore($members[0] ?? '') ?: ($members[0] ?? '');
        if ($members !== []) {
            $cores = [];
            foreach ($members as $m) {
                $c = $this->phraseResolver->preferredClusterCore($m);
                if ($c !== '') {
                    $cores[] = $c;
                }
            }
            if ($cores !== []) {
                usort($cores, fn (string $a, string $b): int => mb_strlen($this->phraseResolver->normalizedKey($a))
                    <=> mb_strlen($this->phraseResolver->normalizedKey($b)));
                $auto = $cores[0];
            } else {
                $auto = $this->phraseResolver->pickCanonicalFromMembers($members);
            }
        }

        if ($auto === '') {
            $auto = $meta instanceof SeoTopicClusterMeta ? (string) $meta->canonical_phrase : $clusterKey;
        }

        $payload = [
            'canonical_phrase' => $auto,
            'normalized_canonical' => $this->phraseResolver->normalizedKey($auto),
            'confidence' => 'high',
            'needs_review' => false,
        ];
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_topic_cluster_meta', 'canonical_source')) {
            $payload['canonical_source'] = self::SOURCE_AUTO;
        }
        SeoTopicClusterMeta::query()->updateOrCreate(
            ['site_id' => $siteId, 'cluster_key' => $clusterKey],
            $payload,
        );

        TopicClusterDirtyState::mark($siteId, 'canonical_reset_auto');

        return [
            'cluster_key' => $clusterKey,
            'canonical_phrase' => $auto,
        ];
    }

    /**
     * @return array{attached: int, detached: int}
     */
    public function reevaluateMembershipForCanonical(int $siteId, string $clusterKey, string $canonical): array
    {
        $attached = 0;
        $detached = 0;
        $touched = [$clusterKey => true];

        $keywordIds = KeywordClusterSiteScope::keywordIds($siteId);
        if ($keywordIds === []) {
            return ['attached' => 0, 'detached' => 0];
        }

        $rows = SeoKeywordClassification::query()
            ->whereIn('keyword_id', $keywordIds)
            ->with(['keyword:id,phrase'])
            ->get();

        foreach ($rows as $row) {
            if (! $row instanceof SeoKeywordClassification || ! $this->eligibility->isSeoEligible($row)) {
                continue;
            }
            $phrase = trim((string) ($row->keyword?->phrase ?? $row->normalized_text ?? ''));
            if ($phrase === '') {
                continue;
            }

            $matches = $this->matchesCanonical($phrase, $canonical);
            $current = trim((string) ($row->cluster_key ?? ''));

            if ($matches) {
                if ($current !== $clusterKey) {
                    $row->cluster_key = $clusterKey;
                    $row->save();
                    $this->resolver->recordAlias($siteId, $clusterKey, $phrase);
                    $attached++;
                    if ($current !== '') {
                        $touched[$current] = true;
                    }
                }
            } elseif ($current === $clusterKey) {
                $row->cluster_key = null;
                $row->save();
                $detached++;
            }
        }

        $this->singletonPruner->prune($siteId, $touched);

        foreach (array_keys($touched) as $key) {
            $canon = $key === $clusterKey
                ? $canonical
                : ($this->resolver->canonicalForCluster($siteId, $key) ?? '');
            if ($canon !== '') {
                $this->dnaService->rebuildForCluster($siteId, $key, $canon);
            }
        }

        return ['attached' => $attached, 'detached' => $detached];
    }

    private function matchesCanonical(string $phrase, string $canonical): bool
    {
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
     * @return list<string>
     */
    private function memberPhrases(int $siteId, string $clusterKey): array
    {
        $ids = app(KeywordClusterQuery::class)->memberKeywordIds($siteId, $clusterKey);
        if ($ids === []) {
            return [];
        }

        return \Omnichannel\Addons\SearchFoundation\Models\Keyword::query()
            ->whereIn('id', $ids)
            ->orderBy('phrase')
            ->pluck('phrase')
            ->map(static fn ($p): string => trim((string) $p))
            ->filter(static fn (string $p): bool => $p !== '')
            ->values()
            ->all();
    }

    public static function isManual(?SeoTopicClusterMeta $meta): bool
    {
        if (! $meta instanceof SeoTopicClusterMeta) {
            return false;
        }

        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_topic_cluster_meta', 'canonical_source')) {
            return false;
        }

        return (string) ($meta->canonical_source ?? self::SOURCE_AUTO) === self::SOURCE_MANUAL;
    }
}
