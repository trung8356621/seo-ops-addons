<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterResolverService;

/**
 * Post-apply hooks: canonical meta + DNA rebuild (shared by apply/batch/recluster).
 */
final class TopicClusterPostApplyService
{
    public function __construct(
        private readonly CanonicalClusterResolverService $resolver,
        private readonly KeywordDnaService $dnaService,
    ) {}

    /**
     * @param  list<int>  $keywordIds
     */
    public function afterClusterAssignment(
        int $siteId,
        string $clusterKey,
        array $keywordIds,
        string $representativeLabel,
        string $confidence = 'high',
        bool $needsReview = false,
    ): void {
        if ($siteId <= 0 || trim($clusterKey) === '' || $keywordIds === []) {
            return;
        }

        $phrases = Keyword::query()
            ->whereIn('id', $keywordIds)
            ->pluck('phrase')
            ->map(static fn ($p): string => trim((string) $p))
            ->filter(static fn (string $p): bool => $p !== '')
            ->values()
            ->all();

        if ($phrases === [] && trim($representativeLabel) !== '') {
            $phrases = [trim($representativeLabel)];
        }

        $canonical = $this->resolver->upsertClusterMeta(
            siteId: $siteId,
            clusterKey: $clusterKey,
            memberPhrases: $phrases,
            confidence: $confidence,
            needsReview: $needsReview,
        );

        $this->dnaService->rebuildForCluster($siteId, $clusterKey, $canonical);
    }

    /**
     * Resolve cluster key — reuse existing canonical cluster when safe.
     *
     * @param  list<int>  $memberIds
     */
    public function resolveClusterKey(
        int $siteId,
        string $representativeLabel,
        array $memberIds,
        TopicClusterClusterKeyGenerator $generator,
    ): string {
        $match = $this->resolver->resolveMatch($siteId, $representativeLabel);
        if ($match !== null && $match->confidence === 'high' && ! $match->needsReview) {
            return $match->clusterKey;
        }

        return $generator->generate(
            siteId: $siteId,
            representativeLabel: $representativeLabel,
            sortedKeywordIds: $memberIds,
        );
    }
}
