<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchFoundation\Support\KeywordOrphanCleanup;

/**
 * Keyword + site-meta persistence.
 * Link SoT = seo_link_maps (ArticleLinkContextMapService). Legacy seo_links / keyword_link dropped.
 */
final class KeywordPersistenceService
{
    public function __construct(
        private readonly KeywordMetaRepository $metaRepository,
    ) {}

    /**
     * @param  array<string, mixed>|null  $metrics
     */
    public function upsert(
        string $phrase,
        string $type,
        int $siteId,
        ?string $targetUrl = null,
        ?int $parentId = null,
        ?array $metrics = null,
        ?int $searchVolume = null,
        ?float $difficulty = null,
        ?int $sourceArticleId = null,
        ?int $targetArticleId = null,
        bool $isNofollow = false,
    ): ?Keyword {
        // Legacy seo_links attach args retained for call-site compatibility; unused after cutover.
        unset($sourceArticleId, $targetArticleId, $isNofollow);

        $phrase = Keyword::preparePhraseForStorage($phrase);
        if ($phrase === '') {
            return null;
        }

        if ($siteId <= 0) {
            throw new \InvalidArgumentException('Keyword site_id is required.');
        }

        $keyword = Keyword::query()
            ->whereRaw('phrase COLLATE utf8mb4_unicode_ci = ?', [$phrase])
            ->first();

        if ($keyword === null) {
            $keyword = Keyword::query()->create([
                'phrase' => $phrase,
                'type' => $type,
                'parent_id' => $parentId,
            ]);
        }

        $updates = [];
        if ($parentId !== null && (int) ($keyword->parent_id ?? 0) !== $parentId) {
            $updates['parent_id'] = $parentId;
        }

        if ($keyword->type !== $type && $keyword->wasRecentlyCreated) {
            $updates['type'] = $type;
        }

        if ($updates !== []) {
            $keyword->update($updates);
        }

        $metrics = $this->metricsWithRescrapeKeep($type, $metrics);

        $this->metaRepository->upsertSiteBundle(
            keyword: $keyword,
            siteId: $siteId,
            targetUrl: $targetUrl,
            metrics: $metrics,
            searchVolume: $searchVolume,
            difficulty: $difficulty,
        );

        return $keyword->fresh(['metas']);
    }

    /**
     * @param  array<string, mixed>|null  $metrics
     */
    public function upsertMeta(
        Keyword $keyword,
        int $siteId,
        ?string $targetUrl = null,
        ?array $metrics = null,
        ?int $searchVolume = null,
        ?float $difficulty = null,
        string $linkType = 'internal',
    ): void {
        unset($linkType);

        if ($siteId <= 0) {
            throw new \InvalidArgumentException('Keyword link site_id is required.');
        }

        $metrics = $this->metricsWithRescrapeKeep((string) $keyword->type, $metrics);

        $this->metaRepository->upsertSiteBundle(
            keyword: $keyword,
            siteId: $siteId,
            targetUrl: $targetUrl,
            metrics: $metrics,
            searchVolume: $searchVolume,
            difficulty: $difficulty,
        );
    }

    public function detachKeywordFromSite(Keyword $keyword, int $siteId): void
    {
        if ($siteId <= 0) {
            return;
        }

        SeoLinkMap::query()
            ->where('keyword_id', $keyword->id)
            ->whereHas(
                'sourceArticle',
                static fn ($query) => $query->where('site_id', $siteId),
            )
            ->delete();

        $this->metaRepository->detachSite((int) $keyword->id, $siteId);
    }

    /**
     * @param  array<string, mixed>|null  $metrics
     * @return array<string, mixed>|null
     */
    private function metricsWithRescrapeKeep(string $type, ?array $metrics): ?array
    {
        if (! in_array($type, [Keyword::TYPE_FREE, Keyword::TYPE_SUGGEST], true)) {
            return $metrics;
        }

        if (is_array($metrics) && ($metrics[Keyword::METRIC_RESCRAPE_KEEP] ?? false) === true) {
            return $metrics;
        }

        if ($type === Keyword::TYPE_FREE) {
            return array_merge($metrics ?? [], [Keyword::METRIC_RESCRAPE_KEEP => true]);
        }

        return $metrics;
    }

    public function normalizeTargetUrl(?string $targetUrl): ?string
    {
        $targetUrl = trim((string) ($targetUrl ?? ''));

        return $targetUrl !== '' ? $targetUrl : null;
    }

    /**
     * Gộp keyword bị cắt mất prefix (vd. "àu sắc..." → "màu sắc...") vào bản canonical.
     */
    public function mergeSuffixTruncatedKeywords(Keyword $canonical, int $siteId): void
    {
        if ($siteId <= 0) {
            return;
        }

        $canonicalNorm = mb_strtolower(Keyword::decodePhrase((string) $canonical->phrase));
        if ($canonicalNorm === '') {
            return;
        }

        Keyword::query()
            ->forSite($siteId)
            ->where('id', '!=', $canonical->id)
            ->orderBy('id')
            ->chunkById(100, function ($keywords) use ($canonical, $siteId, $canonicalNorm): void {
                foreach ($keywords as $candidate) {
                    if (! $candidate instanceof Keyword) {
                        continue;
                    }

                    $candidateNorm = mb_strtolower(Keyword::decodePhrase((string) $candidate->phrase));
                    if (
                        $candidateNorm === ''
                        || $candidateNorm === $canonicalNorm
                        || mb_strlen($candidateNorm) >= mb_strlen($canonicalNorm)
                        || ! str_ends_with($canonicalNorm, $candidateNorm)
                    ) {
                        continue;
                    }

                    $this->absorbKeywordInto($candidate, $canonical, $siteId);
                }
            });
    }

    private function absorbKeywordInto(Keyword $from, Keyword $to, int $siteId): void
    {
        $from->loadMissing(['metas']);

        $this->metaRepository->upsertSiteBundle(
            keyword: $to,
            siteId: $siteId,
            targetUrl: $this->metaRepository->getSiteTargetUrl((int) $to->id, $siteId)
                ?? $this->metaRepository->getSiteTargetUrl((int) $from->id, $siteId),
            searchVolume: $this->metaRepository->getSiteSearchVolume((int) $to->id, $siteId)
                ?? $this->metaRepository->getSiteSearchVolume((int) $from->id, $siteId),
            difficulty: $this->metaRepository->getSiteDifficulty((int) $to->id, $siteId)
                ?? $this->metaRepository->getSiteDifficulty((int) $from->id, $siteId),
            metrics: $this->metaRepository->keepOnRescrapeForSite($to, $siteId)
                ? [Keyword::METRIC_RESCRAPE_KEEP => true]
                : ($this->metaRepository->keepOnRescrapeForSite($from, $siteId)
                    ? [Keyword::METRIC_RESCRAPE_KEEP => true]
                    : null),
        );

        $fromMainArticleId = $this->metaRepository->getMainArticleId((int) $from->id);
        if ($fromMainArticleId !== null && $this->metaRepository->getMainArticleId((int) $to->id) === null) {
            $this->metaRepository->setMainArticleId((int) $to->id, $fromMainArticleId);
        }

        $this->metaRepository->mergeTagIds((int) $to->id, $this->metaRepository->getTagIds((int) $from->id));

        $this->detachKeywordFromSite($from, $siteId);

        if (
            ! $from->linkMaps()->exists()
            && ! $from->metas()->exists()
            && ! $from->children()->exists()
        ) {
            $from->delete();

            return;
        }

        KeywordOrphanCleanup::deleteUnusedByIds([(int) $from->id]);
    }
}
