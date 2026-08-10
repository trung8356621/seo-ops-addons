<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Support\WordPressPermalinkBuilder;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

final class ArticleInternalLinkSearchService
{
    public function __construct(
        private readonly WordPressArticleContentService $wordPressContent,
        private readonly WordPressPermalinkBuilder $permalinkBuilder,
        private readonly ArticleLinkSuggestionCandidateRetriever $candidateRetriever,
    ) {}

    /**
     * Tìm bài cùng site để chèn link nội bộ nhanh trong editor.
     * Ưu tiên relevance score (title/slug/keyword/heading), không sort theo updated_at thuần.
     *
     * @return list<array{id: int, title: string, url: string, label: string, score?: int, match_reason?: string}>
     */
    public function search(int $siteId, int $excludeArticleId, string $query, int $limit = 15): array
    {
        if ($siteId <= 0) {
            return [];
        }

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min(30, $limit));

        $current = SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereKey($excludeArticleId)
            ->first(['id', 'site_id', 'title', 'slug']);

        if ($current instanceof SeoArticle) {
            $ranked = $this->candidateRetriever->searchRanked($current, $query, $limit);
            if ($ranked !== []) {
                return array_map(static function (array $row): array {
                    return [
                        'id' => (int) $row['id'],
                        'title' => (string) $row['title'],
                        'url' => (string) $row['url'],
                        'label' => sprintf('#%d · %s', $row['id'], $row['title']),
                        'score' => (int) ($row['score'] ?? 0),
                        'match_reason' => (string) ($row['match_reason'] ?? ''),
                    ];
                }, $ranked);
            }
        }

        // Fallback hẹp: title LIKE + exclude current (khi index rank không có kết quả).
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $query);
        $builder = ArticleResource::getEloquentQuery()
            ->with(['site', 'articleMetas'])
            ->where('site_id', $siteId)
            ->where('id', '!=', $excludeArticleId)
            ->notContentArchived()
            ->where(function (Builder $inner) use ($query, $escaped): void {
                $inner->where('title', 'like', '%'.$escaped.'%')
                    ->orWhere('slug', 'like', '%'.$escaped.'%');

                if (ctype_digit($query)) {
                    $inner->orWhere('id', (int) $query);
                }
            });

        return $builder
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (SeoArticle $article): ?array => $this->formatResult($article))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, title: string, url: string, label: string}|null
     */
    private function formatResult(SeoArticle $article): ?array
    {
        $title = trim((string) $article->title);
        if ($title === '') {
            return null;
        }

        $url = $this->resolveArticleUrl($article);
        if ($url === '') {
            return null;
        }

        return [
            'id' => (int) $article->id,
            'title' => $title,
            'url' => $url,
            'label' => sprintf('#%d · %s', $article->id, $title),
        ];
    }

    private function resolveArticleUrl(SeoArticle $article): string
    {
        $article->loadMissing('site', 'articleMetas');

        $cached = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', 'wp_permalink')
            ?->meta_value ?? ''));
        $slug = trim((string) ($article->slug ?? ''));

        $resolved = trim($this->permalinkBuilder->resolve(
            $article,
            $cached,
            $slug !== '' ? $slug : null,
        ));
        if ($resolved !== '') {
            return $resolved;
        }

        $site = $article->site;
        if (! $site instanceof Site) {
            return '';
        }

        $base = rtrim($this->wordPressContent->getPermalinkBase($site), '/');
        if ($base === '' || $slug === '') {
            return '';
        }

        return $base.'/'.ltrim($slug, '/');
    }
}
