<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\AiPrompt\Services\OutlineSkipListMatcher;
use Omnichannel\Addons\Content\Models\SeoArticleHeading;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @deprecated Cross-article heading similarity is retired.
 * Kept for historical/debug callers only — active outline save/generate must not invoke this.
 */
class HeadingDuplicateCheckService
{
    private const DEFAULT_LIMIT = 10;

    public function __construct(
        private readonly OutlineSkipListMatcher $skipListMatcher,
    ) {}

    /**
     * Tìm heading trùng slug tuyệt đối trong site.
     *
     * @param  list<string>  $skipSqlPatterns  pattern SQL LIKE đã normalize (Lớp 2)
     * @return Collection<int, SeoArticleHeading>
     */
    public function checkExactMatch(
        string $slug,
        int $siteId,
        ?int $excludeArticleId = null,
        ?int $level = null,
        array $skipSqlPatterns = [],
    ): Collection {
        $slug = trim($slug);
        if ($slug === '') {
            return new Collection;
        }

        $query = $this->siteScopedQuery($siteId, $excludeArticleId)
            ->where('heading_slug', $slug);

        if ($level !== null && $level > 0) {
            $query->where('level', $level);
        }

        if ($skipSqlPatterns !== []) {
            $this->skipListMatcher->applyNotLikeFilters($query, $skipSqlPatterns);
        }

        return $query->limit(self::DEFAULT_LIMIT)->get();
    }

    /**
     * Tìm heading trùng ngữ nghĩa, trả về kèm `score` (relevance) giảm dần.
     * Giữ Natural Language Mode; loại kết quả thuộc Skip List bằng NOT LIKE.
     *
     * @param  list<string>  $skipSqlPatterns  pattern SQL LIKE đã normalize (Lớp 2)
     * @return Collection<int, SeoArticleHeading> mỗi record có thêm thuộc tính ảo `score`
     */
    public function checkSemanticMatch(
        string $text,
        int $siteId,
        ?int $excludeArticleId = null,
        ?int $level = null,
        array $skipSqlPatterns = [],
    ): Collection {
        $text = trim($text);
        if ($text === '') {
            return new Collection;
        }

        $query = $this->siteScopedQuery($siteId, $excludeArticleId)
            ->select('seo_article_headings.*')
            ->selectRaw('MATCH(heading_text) AGAINST(? IN NATURAL LANGUAGE MODE) AS score', [$text])
            ->whereRaw('MATCH(heading_text) AGAINST(? IN NATURAL LANGUAGE MODE)', [$text]);

        if ($level !== null && $level > 0) {
            $query->where('level', $level);
        }

        if ($skipSqlPatterns !== []) {
            $this->skipListMatcher->applyNotLikeFilters($query, $skipSqlPatterns);
        }

        return $query
            ->orderByDesc('score')
            ->limit(self::DEFAULT_LIMIT)
            ->get();
    }

    /**
     * @return Builder<SeoArticleHeading>
     */
    private function siteScopedQuery(int $siteId, ?int $excludeArticleId = null): Builder
    {
        $query = SeoArticleHeading::query()
            ->with('article:id,title,slug,site_id')
            ->whereHas('article', function (Builder $sub) use ($siteId): void {
                $sub->where('site_id', $siteId);
            });

        if ($excludeArticleId !== null && $excludeArticleId > 0) {
            $query->where('article_id', '!=', $excludeArticleId);
        }

        return $query;
    }
}
