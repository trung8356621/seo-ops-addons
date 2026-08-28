<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterResolverService;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpClusterTopicalProfileBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpTopicalProfileStaleState;
use RuntimeException;

final class CreateManualTopicClusterService
{
    public function __construct(
        private readonly TopicClusterClusterKeyGenerator $keyGenerator,
        private readonly CanonicalClusterPhraseResolver $phraseResolver,
        private readonly CanonicalClusterResolverService $resolver,
        private readonly UpdateClusterCanonicalService $canonicalUpdater,
        private readonly KeywordClusterDetailBuilder $detailBuilder,
    ) {}

    public function normalizedExists(int $siteId, string $phrase): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        $normalized = $this->phraseResolver->normalizedKey($this->cleanPhrase($phrase));
        if ($normalized === '') {
            return false;
        }

        return SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->where('normalized_canonical', $normalized)
            ->exists();
    }

    /**
     * Create manual cluster meta, then run the same targeted membership resolver as Rename.
     *
     * @return array{
     *     cluster_key: string,
     *     label: string,
     *     keyword_count: int,
     *     article_count: int,
     *     internal_link_count: int,
     *     topical_share: float,
     *     intent: string,
     *     coverage: string,
     *     canonical_source: string,
     *     state: string,
     *     attached: int,
     *     detached: int
     * }
     */
    public function create(int $siteId, string $canonicalPhrase): array
    {
        $phrase = $this->cleanPhrase($canonicalPhrase);
        if ($siteId <= 0 || $phrase === '') {
            throw new RuntimeException('invalid_input');
        }
        if (mb_strlen($phrase) > 255) {
            throw new RuntimeException('canonical_too_long');
        }

        if ($this->normalizedExists($siteId, $phrase)) {
            throw new RuntimeException('duplicate_cluster');
        }

        $normalized = $this->phraseResolver->normalizedKey($phrase);
        $clusterKey = $this->keyGenerator->generate($siteId, $phrase, []);

        $payload = [
            'canonical_phrase' => $phrase,
            'normalized_canonical' => $normalized,
            'confidence' => 'high',
            'needs_review' => false,
        ];
        if (\Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasColumn('seo_topic_cluster_meta', 'canonical_source')) {
            $payload['canonical_source'] = SeoTopicClusterMeta::SOURCE_MANUAL;
        }

        SeoTopicClusterMeta::query()->updateOrCreate(
            ['site_id' => $siteId, 'cluster_key' => $clusterKey],
            $payload,
        );
        $this->resolver->recordAlias($siteId, $clusterKey, $phrase);

        // Same targeted path as UpdateClusterCanonicalService::setManualCanonical (no full-domain recluster).
        $stats = $this->canonicalUpdater->reevaluateMembershipForCanonical($siteId, $clusterKey, $phrase);

        TopicClusterDirtyState::mark($siteId, 'manual_cluster_created');
        SiteMcpTopicalProfileStaleState::mark($siteId, 'manual_cluster_created');

        $detail = $this->detailBuilder->build($siteId, $clusterKey);
        $keywordCount = (int) ($detail['keyword_count'] ?? 0);
        $shareMap = app(SiteMcpClusterTopicalProfileBuilder::class)->topicalShareMap($siteId);

        return [
            'cluster_key' => $clusterKey,
            'label' => (string) ($detail['label'] ?? $phrase),
            'keyword_count' => $keywordCount,
            'article_count' => (int) ($detail['article_count'] ?? 0),
            'internal_link_count' => (int) ($detail['internal_link_count'] ?? $detail['internal_links'] ?? 0),
            'topical_share' => (float) ($shareMap[$clusterKey] ?? 0.0),
            'intent' => (string) ($detail['intent'] ?? ''),
            'coverage' => (string) ($detail['coverage'] ?? 'unknown'),
            'canonical_source' => SeoTopicClusterMeta::SOURCE_MANUAL,
            'state' => $keywordCount === 0 ? 'planned' : 'active',
            'attached' => (int) ($stats['attached'] ?? 0),
            'detached' => (int) ($stats['detached'] ?? 0),
        ];
    }

    private function cleanPhrase(string $phrase): string
    {
        return trim(preg_replace('/\s+/u', ' ', $phrase) ?? $phrase);
    }
}
