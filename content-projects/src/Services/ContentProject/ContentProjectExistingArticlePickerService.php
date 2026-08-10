<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side Existing Article search/resolve for Content Project manual attach modal.
 * Does not create articles — only finds same-site Laravel Articles.
 */
final class ContentProjectExistingArticlePickerService
{
    private const DEFAULT_LIMIT = 12;

    /**
     * @return list<array{
     *     id: int,
     *     title: string,
     *     slug: string,
     *     wp_post_id: int|null,
     *     permalink: string,
     *     domain: string,
     *     label_lines: list<string>
     * }>
     */
    public function search(int $siteId, string $query, int $limit = self::DEFAULT_LIMIT): array
    {
        if ($siteId <= 0) {
            return [];
        }

        $query = trim($query);
        $limit = max(1, min(30, $limit));
        $domain = $this->siteDomain($siteId);

        $builder = SeoArticle::query()
            ->where('site_id', $siteId)
            ->notContentArchived()
            ->with('wordpressLink:article_id,wp_post_id')
            ->orderByDesc('updated_at')
            ->limit($limit);

        if ($query !== '') {
            $this->applySearchFilter($builder, $query);
        }

        return $builder
            ->get(['id', 'site_id', 'title', 'slug'])
            ->map(fn (SeoArticle $article): array => $this->formatRow($article, $domain))
            ->values()
            ->all();
    }

    /**
     * Resolve pasted WP URL / WP post ID / Laravel Article ID to exactly one same-site article.
     *
     * @return array{ok: bool, article_id: int|null, reason: string, row: ?array<string, mixed>}
     */
    public function resolveDirect(int $siteId, string $input): array
    {
        $input = trim($input);
        if ($siteId <= 0 || $input === '') {
            return ['ok' => false, 'article_id' => null, 'reason' => 'empty_input', 'row' => null];
        }

        $domain = $this->siteDomain($siteId);
        $candidates = [];

        if (ctype_digit($input)) {
            $id = (int) $input;
            $byArticleId = SeoArticle::query()
                ->where('site_id', $siteId)
                ->whereKey($id)
                ->notContentArchived()
                ->with('wordpressLink:article_id,wp_post_id')
                ->first(['id', 'site_id', 'title', 'slug']);
            if ($byArticleId instanceof SeoArticle) {
                $candidates[(int) $byArticleId->id] = $byArticleId;
            }

            $byWp = SeoArticle::query()
                ->where('site_id', $siteId)
                ->whereWpPostId($id)
                ->notContentArchived()
                ->with('wordpressLink:article_id,wp_post_id')
                ->get(['id', 'site_id', 'title', 'slug']);
            foreach ($byWp as $article) {
                $candidates[(int) $article->id] = $article;
            }
        } else {
            $permalinkHints = $this->permalinkHintsFromInput($input);
            foreach ($permalinkHints as $hint) {
                foreach ($this->findByPermalinkHint($siteId, $hint) as $article) {
                    $candidates[(int) $article->id] = $article;
                }
            }

            $slug = $this->slugFromInput($input);
            if ($slug !== '') {
                $bySlug = SeoArticle::query()
                    ->where('site_id', $siteId)
                    ->where('slug', $slug)
                    ->notContentArchived()
                    ->with('wordpressLink:article_id,wp_post_id')
                    ->get(['id', 'site_id', 'title', 'slug']);
                foreach ($bySlug as $article) {
                    $candidates[(int) $article->id] = $article;
                }
            }
        }

        $count = count($candidates);
        if ($count === 0) {
            return ['ok' => false, 'article_id' => null, 'reason' => 'not_found', 'row' => null];
        }
        if ($count > 1) {
            return ['ok' => false, 'article_id' => null, 'reason' => 'ambiguous', 'row' => null];
        }

        /** @var SeoArticle $article */
        $article = array_values($candidates)[0];
        $row = $this->formatRow($article, $domain);

        return [
            'ok' => true,
            'article_id' => (int) $article->id,
            'reason' => 'resolved',
            'row' => $row,
        ];
    }

    private function applySearchFilter(Builder $builder, string $query): void
    {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $query);

        $builder->where(function (Builder $inner) use ($query, $escaped): void {
            $inner->where('title', 'like', '%'.$escaped.'%')
                ->orWhere('slug', 'like', '%'.$escaped.'%');

            if (ctype_digit($query)) {
                $id = (int) $query;
                $inner->orWhere('id', $id)
                    ->orWhere(function (Builder $wpMatch) use ($id): void {
                        $wpMatch->whereWpPostId($id);
                    });
            }

            $permalinkHints = $this->permalinkHintsFromInput($query);
            if ($permalinkHints !== [] && Schema::connection('omi_seo_ai')->hasTable('article_metas')) {
                $inner->orWhereIn('id', function ($sub) use ($permalinkHints): void {
                    $sub->select('article_id')
                        ->from((new ArticleMeta)->getTable())
                        ->where('meta_key', 'wp_permalink')
                        ->where(function ($metaQ) use ($permalinkHints): void {
                            foreach ($permalinkHints as $hint) {
                                $metaQ->orWhere('meta_value', $hint)
                                    ->orWhere('meta_value', 'like', '%'.$hint.'%');
                            }
                        });
                });
            }
        });
    }

    /**
     * @return list<string>
     */
    private function permalinkHintsFromInput(string $input): array
    {
        $hints = [];
        $trimmed = trim($input);
        if ($trimmed === '') {
            return [];
        }

        $hints[] = $trimmed;
        if (filter_var($trimmed, FILTER_VALIDATE_URL)) {
            $path = parse_url($trimmed, PHP_URL_PATH);
            if (is_string($path) && $path !== '' && $path !== '/') {
                $hints[] = $path;
                $hints[] = trim($path, '/');
            }
            $hints[] = rtrim($trimmed, '/');
        }

        return array_values(array_unique(array_filter($hints, static fn (string $v): bool => $v !== '')));
    }

    private function slugFromInput(string $input): string
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return '';
        }
        if (filter_var($trimmed, FILTER_VALIDATE_URL)) {
            $path = parse_url($trimmed, PHP_URL_PATH);
            if (! is_string($path) || $path === '' || $path === '/') {
                return '';
            }

            return trim($path, '/');
        }

        if (str_contains($trimmed, '/') || str_contains($trimmed, ' ')) {
            return trim($trimmed, '/');
        }

        return $trimmed;
    }

    /**
     * @return list<SeoArticle>
     */
    private function findByPermalinkHint(int $siteId, string $hint): array
    {
        if ($hint === '' || ! Schema::connection('omi_seo_ai')->hasTable('article_metas')) {
            return [];
        }

        $ids = ArticleMeta::query()
            ->where('meta_key', 'wp_permalink')
            ->where(function ($q) use ($hint): void {
                $q->where('meta_value', $hint)
                    ->orWhere('meta_value', rtrim($hint, '/'))
                    ->orWhere('meta_value', 'like', '%'.$hint.'%');
            })
            ->limit(20)
            ->pluck('article_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereIn('id', $ids)
            ->notContentArchived()
            ->with('wordpressLink:article_id,wp_post_id')
            ->get(['id', 'site_id', 'title', 'slug'])
            ->all();
    }

    /**
     * @return array{
     *     id: int,
     *     title: string,
     *     slug: string,
     *     wp_post_id: int|null,
     *     permalink: string,
     *     domain: string,
     *     label_lines: list<string>
     * }
     */
    private function formatRow(SeoArticle $article, string $domain): array
    {
        $id = (int) $article->id;
        $title = trim((string) ($article->title ?? ''));
        if ($title === '') {
            $title = 'Article #'.$id;
        }
        $slug = trim((string) ($article->slug ?? ''));
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        $permalink = $this->cachedPermalink($id);
        $path = $slug !== '' ? '/'.ltrim($slug, '/').'/' : '';
        if ($permalink !== '') {
            $parsed = parse_url($permalink, PHP_URL_PATH);
            if (is_string($parsed) && $parsed !== '') {
                $path = $parsed;
            }
        }

        $lines = [
            $title,
            'Article #'.$id,
        ];
        if ($wpPostId > 0) {
            $lines[] = 'WP #'.$wpPostId;
        }
        if ($path !== '') {
            $lines[] = $path;
        }
        if ($domain !== '') {
            $lines[] = $domain;
        }

        return [
            'id' => $id,
            'title' => $title,
            'slug' => $slug,
            'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
            'permalink' => $permalink,
            'domain' => $domain,
            'label_lines' => $lines,
        ];
    }

    private function cachedPermalink(int $articleId): string
    {
        if ($articleId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('article_metas')) {
            return '';
        }

        return trim((string) (ArticleMeta::query()
            ->where('article_id', $articleId)
            ->where('meta_key', 'wp_permalink')
            ->value('meta_value') ?? ''));
    }

    private function siteDomain(int $siteId): string
    {
        if ($siteId <= 0) {
            return '';
        }

        try {
            return trim((string) (Site::query()->whereKey($siteId)->value('domain') ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }
}
