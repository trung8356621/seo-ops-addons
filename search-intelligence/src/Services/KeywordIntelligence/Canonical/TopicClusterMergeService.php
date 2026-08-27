<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterAlias;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\TopicClusterMergeResult;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordDnaService;
use RuntimeException;

final class TopicClusterMergeService
{
    public function __construct(
        private readonly CanonicalClusterResolverService $resolver,
        private readonly KeywordClusterQuery $clusterQuery,
        private readonly KeywordDnaService $dnaService,
    ) {}

    public function merge(int $siteId, string $survivorKey, string $loserKey): TopicClusterMergeResult
    {
        $survivorKey = trim($survivorKey);
        $loserKey = trim($loserKey);

        if ($siteId <= 0 || $survivorKey === '' || $loserKey === '' || $survivorKey === $loserKey) {
            throw new RuntimeException('invalid_merge_request');
        }

        if (! $this->clusterQuery->clusterExists($survivorKey) || ! $this->clusterQuery->clusterExists($loserKey)) {
            throw new RuntimeException('cluster_not_found');
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $survivorKey, $loserKey): TopicClusterMergeResult {
            $loserPhrases = $this->memberPhrases($siteId, $loserKey);
            $survivorPhrases = $this->memberPhrases($siteId, $survivorKey);
            $allPhrases = array_values(array_unique([...$survivorPhrases, ...$loserPhrases]));

            $loserCanonical = $this->resolver->canonicalForCluster($siteId, $loserKey) ?? '';
            if ($loserCanonical !== '') {
                $this->resolver->recordAlias($siteId, $survivorKey, $loserCanonical);
            }

            foreach ($loserPhrases as $phrase) {
                $this->resolver->recordAlias($siteId, $survivorKey, $phrase);
            }

            $moved = SeoKeywordClassification::query()
                ->where('cluster_key', $loserKey)
                ->update(['cluster_key' => $survivorKey]);

            if (Schema::connection('omi_seo_ai')->hasTable('seo_keyword_dna')) {
                SeoKeywordDna::query()
                    ->where('site_id', $siteId)
                    ->where('cluster_key', $loserKey)
                    ->update(['cluster_key' => $survivorKey]);
            }

            $canonical = $this->resolver->upsertClusterMeta($siteId, $survivorKey, $allPhrases);

            if (Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta')) {
                SeoTopicClusterMeta::query()
                    ->where('site_id', $siteId)
                    ->where('cluster_key', $loserKey)
                    ->delete();
            }

            if (Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_aliases')) {
                SeoTopicClusterAlias::query()
                    ->where('site_id', $siteId)
                    ->where('cluster_key', $loserKey)
                    ->update(['cluster_key' => $survivorKey]);
            }

            $this->dnaService->rebuildForCluster($siteId, $survivorKey, $canonical);

            return new TopicClusterMergeResult(
                survivorKey: $survivorKey,
                canonicalPhrase: $canonical,
                keywordsMoved: (int) $moved,
                mergedKeys: [$loserKey],
            );
        });
    }

    /**
     * @return list<string>
     */
    private function memberPhrases(int $siteId, string $clusterKey): array
    {
        $ids = $this->clusterQuery->memberKeywordIds($siteId, $clusterKey);

        return \Omnichannel\Addons\SearchFoundation\Models\Keyword::query()
            ->whereIn('id', $ids)
            ->pluck('phrase')
            ->map(static fn ($p): string => trim((string) $p))
            ->filter(static fn (string $p): bool => $p !== '')
            ->values()
            ->all();
    }
}
