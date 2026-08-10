<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\KeywordLink;
use Omnichannel\Addons\SearchFoundation\Models\SeoLink;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchFoundation\Support\KeywordOrphanCleanup;

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

        if ($targetUrl !== null && trim($targetUrl) !== '' && $this->legacyKeywordLinkTableExists()) {
            $this->attachKeywordToLink(
                keyword: $keyword,
                siteId: $siteId,
                targetUrl: $targetUrl,
                linkType: SeoLink::TYPE_INTERNAL,
                sourceArticleId: $sourceArticleId,
                targetArticleId: $targetArticleId,
                isNofollow: $isNofollow,
                metrics: $metrics,
                searchVolume: $searchVolume,
                difficulty: $difficulty,
            );
        }

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
        string $linkType = SeoLink::TYPE_INTERNAL,
    ): void {
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

        $normalizedUrl = $this->normalizeTargetUrl($targetUrl);
        if ($normalizedUrl !== null && $this->legacyKeywordLinkTableExists()) {
            if (! in_array($linkType, [SeoLink::TYPE_INTERNAL, SeoLink::TYPE_EXTERNAL], true)) {
                $linkType = SeoLink::TYPE_INTERNAL;
            }

            $this->attachKeywordToLink(
                keyword: $keyword,
                siteId: $siteId,
                targetUrl: $normalizedUrl,
                linkType: $linkType,
                metrics: $metrics,
                searchVolume: $searchVolume,
                difficulty: $difficulty,
            );
        }
    }

    public function attachSiteLink(
        Keyword $keyword,
        int $siteId,
        string $targetUrl,
        string $linkType = SeoLink::TYPE_INTERNAL,
    ): SeoLink {
        if (! in_array($linkType, [SeoLink::TYPE_INTERNAL, SeoLink::TYPE_EXTERNAL], true)) {
            $linkType = SeoLink::TYPE_INTERNAL;
        }

        return $this->attachKeywordToLink(
            keyword: $keyword,
            siteId: $siteId,
            targetUrl: $targetUrl,
            linkType: $linkType,
        );
    }

    /**
     * @param  array<string, mixed>|null  $metrics
     */
    public function attachKeywordToLink(
        Keyword $keyword,
        int $siteId,
        string $targetUrl,
        string $linkType = SeoLink::TYPE_INTERNAL,
        ?int $sourceArticleId = null,
        ?int $targetArticleId = null,
        bool $isNofollow = false,
        ?array $metrics = null,
        ?int $searchVolume = null,
        ?float $difficulty = null,
    ): SeoLink {
        $targetUrl = $this->normalizeTargetUrl($targetUrl) ?? '';
        if ($targetUrl === '') {
            throw new \InvalidArgumentException('Keyword link target URL is required.');
        }

        $link = $this->resolveOrCreateLink(
            siteId: $siteId,
            url: $targetUrl,
            type: $linkType,
            sourceArticleId: $sourceArticleId,
            targetArticleId: $targetArticleId,
            isNofollow: $isNofollow,
        );

        $existingPivot = $keyword->links()
            ->where('seo_links.id', $link->id)
            ->first()
            ?->pivot;

        $mergedMetrics = $metrics;
        if ($metrics !== null) {
            $existingMetrics = $existingPivot instanceof KeywordLink && is_array($existingPivot->metrics)
                ? $existingPivot->metrics
                : [];
            $mergedMetrics = array_merge($existingMetrics, $metrics);
        }

        $pivotPayload = array_filter([
            'search_volume' => $searchVolume,
            'difficulty' => $difficulty !== null ? (int) round($difficulty) : null,
            'metrics' => $mergedMetrics,
        ], static fn (mixed $value): bool => $value !== null);

        $keyword->links()->syncWithoutDetaching([
            $link->id => $pivotPayload,
        ]);

        return $link->fresh(['keywords']);
    }

    public function resolveOrCreateLink(
        int $siteId,
        string $url,
        string $type,
        ?int $sourceArticleId = null,
        ?int $targetArticleId = null,
        bool $isNofollow = false,
    ): SeoLink {
        $query = SeoLink::query()
            ->where('site_id', $siteId)
            ->where('url', $url)
            ->where('type', $type);

        if ($sourceArticleId !== null) {
            $query->where('source_article_id', $sourceArticleId);
        } else {
            $query->whereNull('source_article_id');
        }

        $link = $query->first();
        if ($link instanceof SeoLink) {
            $link->update([
                'is_nofollow' => $isNofollow,
                'article_id' => $targetArticleId ?? $link->article_id,
            ]);

            return $link->fresh();
        }

        return SeoLink::query()->create([
            'site_id' => $siteId,
            'url' => $url,
            'type' => $type,
            'source_article_id' => $sourceArticleId,
            'article_id' => $targetArticleId,
            'is_nofollow' => $isNofollow,
        ]);
    }

    public function detachArticleOutboundLinks(int $articleId): void
    {
        if ($articleId <= 0) {
            return;
        }

        SeoLink::query()
            ->where('source_article_id', $articleId)
            ->each(function (SeoLink $link): void {
                if (\Illuminate\Support\Facades\Schema::connection($link->getConnectionName())->hasTable('keyword_link')) {
                    $link->keywords()->detach();
                }

                $link->delete();
            });
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

        if (! $this->legacyKeywordLinkTableExists()) {
            return;
        }

        $linkIds = $keyword->links()
            ->where('seo_links.site_id', $siteId)
            ->pluck('seo_links.id')
            ->all();

        if ($linkIds === []) {
            return;
        }

        $keyword->links()->detach($linkIds);

        SeoLink::query()
            ->whereIn('id', $linkIds)
            ->whereDoesntHave('keywords')
            ->delete();
    }

    private function legacyKeywordLinkTableExists(): bool
    {
        $schema = \Illuminate\Support\Facades\Schema::connection((new Keyword)->getConnectionName());

        return $schema->hasTable('keyword_link') && $schema->hasTable('seo_links');
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
        if ($this->legacyKeywordLinkTableExists()) {
            $from->loadMissing(['links']);
        }

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

        if ($this->legacyKeywordLinkTableExists()) {
            foreach ($from->links()->where('seo_links.site_id', $siteId)->get() as $link) {
                if (! $link instanceof SeoLink) {
                    continue;
                }

                $existingUrls = $to->links()
                    ->where('seo_links.site_id', $siteId)
                    ->pluck('seo_links.url');

                $isDuplicate = $existingUrls->contains(
                    fn (mixed $existingUrl): bool => $this->urlsEquivalent((string) $existingUrl, (string) $link->url),
                );

                if ($isDuplicate) {
                    continue;
                }

                $pivot = $link->pivot instanceof KeywordLink ? $link->pivot : null;

                $this->attachKeywordToLink(
                    keyword: $to,
                    siteId: $siteId,
                    targetUrl: (string) $link->url,
                    linkType: (string) $link->type,
                    sourceArticleId: $link->source_article_id !== null ? (int) $link->source_article_id : null,
                    targetArticleId: $link->article_id !== null ? (int) $link->article_id : null,
                    isNofollow: (bool) $link->is_nofollow,
                    metrics: is_array($pivot?->metrics) ? $pivot->metrics : null,
                    searchVolume: $pivot?->search_volume !== null ? (int) $pivot->search_volume : null,
                    difficulty: $pivot?->difficulty !== null ? (float) $pivot->difficulty : null,
                );
            }
        }

        $fromMainArticleId = $this->metaRepository->getMainArticleId((int) $from->id);
        if ($fromMainArticleId !== null && $this->metaRepository->getMainArticleId((int) $to->id) === null) {
            $this->metaRepository->setMainArticleId((int) $to->id, $fromMainArticleId);
        }

        $this->metaRepository->mergeTagIds((int) $to->id, $this->metaRepository->getTagIds((int) $from->id));

        $this->detachKeywordFromSite($from, $siteId);

        $legacyLinksExist = $this->legacyKeywordLinkTableExists() && $from->links()->exists();

        if (
            ! $from->linkMaps()->exists()
            && ! $from->metas()->exists()
            && ! $legacyLinksExist
            && ! $from->children()->exists()
        ) {
            if ($this->legacyKeywordLinkTableExists()) {
                $linkIds = $from->links()->pluck('seo_links.id')->all();
                $from->links()->detach();

                if ($linkIds !== []) {
                    SeoLink::query()
                        ->whereIn('id', $linkIds)
                        ->whereDoesntHave('keywords')
                        ->delete();
                }
            }

            $from->delete();

            return;
        }

        KeywordOrphanCleanup::deleteUnusedByIds([(int) $from->id]);
    }

    private function urlsEquivalent(string $first, string $second): bool
    {
        $firstNorm = rtrim(strtolower(trim($first)), '/');
        $secondNorm = rtrim(strtolower(trim($second)), '/');

        if ($firstNorm === '' || $secondNorm === '') {
            return false;
        }

        if ($firstNorm === $secondNorm) {
            return true;
        }

        return str_ends_with($firstNorm, $secondNorm) || str_ends_with($secondNorm, $firstNorm);
    }
}
