<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Jobs\ReconcileTopicMembershipJob;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterResolverService;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpTopicalProfileStaleState;
use RuntimeException;

final class CreateManualTopicClusterService
{
    public function __construct(
        private readonly TopicClusterClusterKeyGenerator $keyGenerator,
        private readonly CanonicalClusterPhraseResolver $phraseResolver,
        private readonly CanonicalClusterResolverService $resolver,
    ) {}

    public function findByNormalizedCanonical(int $siteId, string $phrase): ?SeoTopicClusterMeta
    {
        if ($siteId <= 0) {
            return null;
        }

        $normalized = $this->phraseResolver->normalizedKey($this->cleanPhrase($phrase));
        if ($normalized === '') {
            return null;
        }

        /** @var SeoTopicClusterMeta|null $meta */
        $meta = SeoTopicClusterMeta::query()
            ->where('site_id', $siteId)
            ->where('normalized_canonical', $normalized)
            ->first();

        return $meta;
    }

    public function normalizedExists(int $siteId, string $phrase): bool
    {
        return $this->findByNormalizedCanonical($siteId, $phrase) !== null;
    }

    /**
     * Fast path: create-or-resolve Topic meta only. Membership repair is queued separately.
     *
     * @return array{
     *     cluster_key: string,
     *     canonical_phrase: string,
     *     created: bool
     * }
     */
    public function prepareManualTopic(int $siteId, string $canonicalPhrase): array
    {
        $phrase = $this->cleanPhrase($canonicalPhrase);
        if ($siteId <= 0 || $phrase === '') {
            throw new RuntimeException('invalid_input');
        }
        if (mb_strlen($phrase) > 255) {
            throw new RuntimeException('canonical_too_long');
        }

        $existing = $this->findByNormalizedCanonical($siteId, $phrase);
        if ($existing instanceof SeoTopicClusterMeta) {
            return [
                'cluster_key' => (string) $existing->cluster_key,
                'canonical_phrase' => (string) ($existing->canonical_phrase ?: $phrase),
                'created' => false,
            ];
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

        TopicClusterDirtyState::mark($siteId, 'manual_cluster_created');
        SiteMcpTopicalProfileStaleState::mark($siteId, 'manual_cluster_created');

        return [
            'cluster_key' => $clusterKey,
            'canonical_phrase' => $phrase,
            'created' => true,
        ];
    }

    /**
     * Prepare Topic then enqueue membership reconciliation (Fix Keywords).
     *
     * @return array{
     *     cluster_key: string,
     *     canonical_phrase: string,
     *     created: bool
     * }
     */
    public function create(int $siteId, string $canonicalPhrase, ?int $requestedBy = null): array
    {
        $prepared = $this->prepareManualTopic($siteId, $canonicalPhrase);
        ReconcileTopicMembershipJob::dispatch(
            $siteId,
            $prepared['cluster_key'],
            $requestedBy,
        );

        return $prepared;
    }

    private function cleanPhrase(string $phrase): string
    {
        return trim(preg_replace('/\s+/u', ' ', $phrase) ?? $phrase);
    }
}
