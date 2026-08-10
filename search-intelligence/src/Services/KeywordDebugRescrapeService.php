<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Illuminate\Support\Facades\DB;

final class KeywordDebugRescrapeService
{
    public function __construct(
        private readonly KeywordDomainResyncService $domainResync,
        private readonly ArticleKeywordLinkReconcileService $articleReconcile,
    ) {}

    /**
     * Xóa một keyword, rồi bóc tách lại toàn bộ keyword từ các bài đã liên kết trước đó.
     *
     * @return array{
     *   phrase: string,
     *   linked_article_ids: array<int>,
     *   deleted: bool,
     *   rescanned: int,
     *   skipped: int,
     *   content_still_contains_phrase: int,
     *   recreated: bool,
     * }
     */
    public function deleteAndRescrapeLinkedArticles(Keyword $keyword): array
    {
        $linkedArticleIds = $this->collectLinkedArticleIds($keyword);
        $phrase = (string) $keyword->phrase;
        $keywordId = (int) $keyword->id;
        $phraseNorm = mb_strtolower(Keyword::decodePhrase($phrase));

        DB::connection((new Keyword)->getConnectionName())->transaction(function () use ($keyword): void {
            $this->domainResync->deleteKeywordRecord($keyword);
        });

        $deleted = Keyword::query()->whereKey($keywordId)->doesntExist();

        $rescanned = 0;
        $skipped = 0;
        $contentStillContainsPhrase = 0;

        foreach ($linkedArticleIds as $articleId) {
            $article = SeoArticle::query()->find($articleId);
            if (! $article instanceof SeoArticle) {
                $skipped++;

                continue;
            }

            $content = $this->articleReconcile->resolveArticleContent($article);
            if (trim($content) === '') {
                $skipped++;

                continue;
            }

            if ($phraseNorm !== '' && $this->contentContainsPhrase($content, $phraseNorm)) {
                $contentStillContainsPhrase++;
            }

            $this->articleReconcile->reconcileForArticle($article, $content, [$phrase]);
            $rescanned++;
        }

        $recreated = $phraseNorm !== ''
            && Keyword::query()->whereRaw('LOWER(phrase) = ?', [$phraseNorm])->exists();

        return [
            'phrase' => $phrase,
            'linked_article_ids' => $linkedArticleIds,
            'deleted' => $deleted,
            'rescanned' => $rescanned,
            'skipped' => $skipped,
            'content_still_contains_phrase' => $contentStillContainsPhrase,
            'recreated' => $recreated,
        ];
    }

    /**
     * @return array<int>
     */
    private function collectLinkedArticleIds(Keyword $keyword): array
    {
        return SeoLinkMap::query()
            ->where('keyword_id', $keyword->id)
            ->whereNotNull('source_article_id')
            ->pluck('source_article_id')
            ->filter(static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function contentContainsPhrase(string $content, string $phraseNorm): bool
    {
        if ($phraseNorm === '') {
            return false;
        }

        return mb_stripos(Keyword::decodePhrase(strip_tags($content)), Keyword::decodePhrase($phraseNorm)) !== false;
    }
}
