<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support;

use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\KeywordMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchFoundation\Support\KeywordOrphanCleanup;
use Illuminate\Support\Facades\Log;

final class KeywordFocusAttach
{
    public static function syncMainKeyword(SeoArticle $article, int $siteId, int $_userId, string $phrase): void
    {
        $phrase = Keyword::preparePhraseForStorage($phrase);
        $articleId = (int) $article->id;

        if ($phrase === '') {
            $article->articleMetas()->where('meta_key', 'seo_focus_keyword')->delete();
            self::clearMainArticleMetaForArticle($articleId);

            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_focus_keyword'],
            ['meta_value' => $phrase],
        );

        self::attachMainKeyword($article, $siteId, $phrase);
    }

    public static function attachMainKeyword(SeoArticle $article, int $siteId, string $phrase): ?int
    {
        $phrase = Keyword::preparePhraseForStorage($phrase);
        if ($phrase === '') {
            return null;
        }

        $articleSiteId = (int) ($article->site_id ?? 0);
        $articleId = (int) $article->id;
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? $article->wp_post_id ?? 0);

        if ($siteId <= 0 || $articleSiteId <= 0 || $articleSiteId !== $siteId) {
            Log::warning('seo.cross_site_relation_rejected', [
                'keyword_id' => null,
                'keyword_site_id' => $siteId,
                'article_id' => $articleId,
                'article_site_id' => $articleSiteId,
                'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
                'source' => 'KeywordFocusAttach::attachMainKeyword',
                'resolver' => 'site_id_guard',
                'url' => null,
                'reason' => 'keyword_site_article_site_mismatch',
            ]);

            return null;
        }

        $metaRepository = app(KeywordMetaRepository::class);
        $persistence = app(KeywordPersistenceService::class);

        // Article relation is the destination SoT — do not write fabricated domain+slug
        // (or any duplicate) into site.*.target_url. Pending resolve only runs when WP-synced.
        $keyword = $persistence->upsert(
            $phrase,
            Keyword::TYPE_NORMAL,
            $siteId,
            null,
            targetArticleId: $articleId,
        );

        if ($keyword === null) {
            return null;
        }

        $keywordId = (int) $keyword->id;
        $persistence->mergeSuffixTruncatedKeywords($keyword, $siteId);

        // Merge may delete absorbed siblings; re-resolve canonical before writing meta.
        $keyword = Keyword::query()->find($keywordId);
        if ($keyword === null) {
            $keyword = $persistence->upsert(
                $phrase,
                Keyword::TYPE_NORMAL,
                $siteId,
                null,
                targetArticleId: $articleId,
            );
            if ($keyword === null) {
                return null;
            }
            $keywordId = (int) $keyword->id;
        }

        // Persist site-scoped focus BEFORE clearing other main_article_id rows.
        // clearMainArticleMetaForArticle may delete legacy `main_article_id` on this
        // same keyword (keep-key is site-scoped only). TYPE_NORMAL upsert often leaves
        // zero other metas, so orphan cleanup would delete the keyword and the later
        // setMainArticleIdForSite insert hits keyword_meta FK 1452.
        $ok = $metaRepository->setMainArticleIdForSite($keywordId, $siteId, $articleId);
        if (! $ok) {
            Log::warning('seo.cross_site_relation_rejected', [
                'keyword_id' => $keywordId,
                'keyword_site_id' => $siteId,
                'article_id' => $articleId,
                'article_site_id' => $articleSiteId,
                'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
                'source' => 'KeywordFocusAttach::attachMainKeyword',
                'resolver' => 'setMainArticleIdForSite',
                'url' => null,
                'reason' => 'set_main_article_rejected',
            ]);

            return null;
        }

        self::clearMainArticleMetaForArticle($articleId, exceptKeywordId: $keywordId, exceptSiteId: $siteId);

        // Drop stale duplicate target_url so it cannot shadow WordPress permalinks.
        $metaRepository->setSiteTargetUrl($keywordId, $siteId, null);

        if ($wpPostId > 0) {
            app(\Omnichannel\Addons\Content\Services\ArticlePendingInternalLinkService::class)->resolveForKeyword($keywordId);
        }

        return $keywordId;
    }

    public static function syncFocusKeywordsFromArticles(int $siteId): int
    {
        if ($siteId <= 0) {
            return 0;
        }

        $synced = 0;
        $userId = (int) (auth()->id() ?? 0);

        SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereHas('articleMetas', static fn ($query) => $query
                ->where('meta_key', 'seo_focus_keyword')
                ->whereNotNull('meta_value')
                ->where('meta_value', '!=', ''))
            ->with(['articleMetas'])
            ->orderBy('id')
            ->chunkById(100, function ($articles) use ($siteId, $userId, &$synced): void {
                foreach ($articles as $article) {
                    if (! $article instanceof SeoArticle) {
                        continue;
                    }

                    $phrase = Keyword::preparePhraseForStorage(trim((string) ($article->articleMetas
                        ->firstWhere('meta_key', 'seo_focus_keyword')?->meta_value ?? '')));

                    if ($phrase === '') {
                        continue;
                    }

                    self::syncMainKeyword($article, $siteId, $userId, $phrase);
                    $synced++;
                }
            });

        return $synced;
    }

    public static function isFocusKeywordForSite(Keyword $keyword, int $siteId): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        $mainArticleId = app(KeywordMetaRepository::class)->getMainArticleIdForSite((int) $keyword->id, $siteId);
        if ($mainArticleId === null) {
            return false;
        }

        return SeoArticle::query()
            ->whereKey($mainArticleId)
            ->where('site_id', $siteId)
            ->exists();
    }

    public static function phraseMatchesFocusOnSite(Keyword $keyword, int $siteId): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        $phraseNorm = mb_strtolower(Keyword::decodePhrase((string) $keyword->phrase));
        if ($phraseNorm === '') {
            return false;
        }

        return SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereHas('articleMetas', static fn ($query) => $query
                ->where('meta_key', 'seo_focus_keyword')
                ->whereRaw('LOWER(meta_value) = ?', [$phraseNorm]))
            ->exists();
    }

    private static function clearMainArticleMetaForArticle(
        int $articleId,
        ?int $exceptKeywordId = null,
        ?int $exceptSiteId = null,
    ): void {
        if ($articleId <= 0) {
            return;
        }

        $attempts = 0;
        $staleKeywordIds = [];

        while (true) {
            $attempts++;
            try {
                $query = KeywordMeta::query()
                    ->where('meta_value', (string) $articleId)
                    ->where(function ($q): void {
                        $q->where('meta_key', KeywordMetaKey::MainArticleId->value)
                            ->orWhere('meta_key', 'like', 'site.%.main_article_id');
                    });

                if ($exceptKeywordId !== null && $exceptKeywordId > 0 && $exceptSiteId !== null && $exceptSiteId > 0) {
                    $keepKey = KeywordMetaKey::siteMainArticleId($exceptSiteId);
                    $legacyKey = KeywordMetaKey::MainArticleId->value;
                    // Keep site-scoped SoT and legacy global on the keyword we are attaching.
                    // Deleting only the legacy row still listed this keyword as "stale" and
                    // orphan-cleanup could remove it before the next meta write.
                    $query->where(function ($q) use ($exceptKeywordId, $keepKey, $legacyKey): void {
                        $q->where('keyword_id', '!=', $exceptKeywordId)
                            ->orWhere(function ($inner) use ($keepKey, $legacyKey): void {
                                $inner->where('meta_key', '!=', $keepKey)
                                    ->where('meta_key', '!=', $legacyKey);
                            });
                    });
                } elseif ($exceptKeywordId !== null && $exceptKeywordId > 0) {
                    $query->where('keyword_id', '!=', $exceptKeywordId);
                }

                $staleKeywordIds = $query->pluck('keyword_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                $query->delete();
                break;
            } catch (\Illuminate\Database\QueryException $e) {
                // MySQL deadlock (1213) under concurrent site-sync keyword attach.
                if ($attempts >= 3 || ! str_contains($e->getMessage(), '1213')) {
                    throw $e;
                }
                usleep(50_000 * $attempts);
            }
        }

        if ($exceptKeywordId !== null && $exceptKeywordId > 0) {
            $staleKeywordIds = array_values(array_filter(
                $staleKeywordIds,
                static fn (int $id): bool => $id !== $exceptKeywordId,
            ));
        }

        if ($staleKeywordIds !== []) {
            KeywordOrphanCleanup::deleteUnusedByIds($staleKeywordIds);
        }
    }
}
