<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Omnichannel\Addons\Content\Services\ArticleLinkContextMapService;
use App\Support\LocalArticleSaveTimer;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;

/**
 * Đối chiếu từ khóa ↔ liên kết outbound khi bài viết được cập nhật / đồng bộ nội dung.
 */
final class ArticleKeywordLinkReconcileService
{
    public function __construct(
        private readonly ArticleLinkContextMapService $linkContextMap,
    ) {}

    /**
     * @param  array<int, string>  $excludeAnchorPhrases
     */
    public function reconcileForArticle(
        SeoArticle $article,
        ?string $contentOverride = null,
        array $excludeAnchorPhrases = [],
    ): void {
        if (! \Omnichannel\Addons\SearchFoundation\Support\KeywordSyncIsolation::allowsAutomaticContentSync()) {
            return;
        }

        $article->loadMissing(['site', 'articleMetas']);

        if ($this->isTaxonomyArticle($article)) {
            return;
        }

        LocalArticleSaveTimer::measure(
            (int) $article->getKey(),
            'linkContextMap.resyncArticle',
            fn () => $this->linkContextMap->resyncArticle($article, $contentOverride, $excludeAnchorPhrases),
        );

        LocalArticleSaveTimer::measure(
            (int) $article->getKey(),
            'refreshMainKeywordDestinationLink',
            fn () => $this->refreshMainKeywordDestinationLink($article),
        );
    }

    public function resolveArticleContent(SeoArticle $article): string
    {
        return trim((string) ($article->body ?? ''));
    }

    private function refreshMainKeywordDestinationLink(SeoArticle $article): void
    {
        $article->loadMissing(['articleMetas', 'site']);

        $focusKeyword = trim((string) ($article->articleMetas->firstWhere('meta_key', 'seo_focus_keyword')?->meta_value ?? ''));
        if ($focusKeyword === '') {
            return;
        }

        $siteId = (int) ($article->site_id ?? 0);
        $userId = (int) (auth()->id() ?? $article->user_id ?? $article->site?->user_id ?? 0);
        if ($siteId <= 0 || $userId <= 0) {
            return;
        }

        KeywordFocusAttach::syncMainKeyword($article, $siteId, $userId, $focusKeyword);
    }

    private function isTaxonomyArticle(SeoArticle $article): bool
    {
        return app(WordPressArticleContentService::class)->isTaxonomyRecord($article);
    }
}
