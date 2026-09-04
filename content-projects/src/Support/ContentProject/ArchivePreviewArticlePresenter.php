<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditCheckIndexUrl;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * View-model cho 1 hàng / slide-over archive preview (không N+1).
 *
 * @phpstan-type RowData array{
 *     item_id: int,
 *     article_id: int,
 *     position: int,
 *     title: string,
 *     keyword: string,
 *     slug: string,
 *     meta_title: string,
 *     meta_description: string,
 *     outline_meta: string,
 *     body_excerpt: string,
 *     image_count: int,
 *     internal_link_count: int,
 *     external_link_count: int,
 *     seo_score: float|null,
 *     task_status: string,
 *     review_status: string,
 *     sync_status: string,
 *     wordpress_post_id: int|null,
 *     wordpress_url: string,
 *     has_public_wordpress_url: bool,
 *     check_index_url: string|null,
 *     wp_sync_error: string,
 *     indexed_at: string|null,
 *     previous_indexed_at: string|null,
 *     indexed_at_label: string|null,
 *     previous_indexed_at_label: string|null,
 *     created_at: string|null,
 *     updated_at: string|null,
 *     completed_at: string|null,
 *     last_saved_at: string|null,
 *     last_synced_at: string|null,
 *     article_exists: bool,
 *     can_edit: bool,
 *     edit_url: string|null,
 *     social_links_count: int,
 * }
 */
final class ArchivePreviewArticlePresenter
{
    public function __construct(
        private readonly ArchiveArticleHistoricalFieldResolver $historicalFields = new ArchiveArticleHistoricalFieldResolver(),
    ) {}

    /**
     * @param  Collection<int, SeoProjectArchiveItem>  $items
     * @param  Collection<int, SeoArticle>|null  $articlesById  optional preloaded map; null = dùng $item->article
     * @param  array<int, int>  $socialCountsByArticleId
     * @return list<RowData>
     */
    public function presentItems(Collection $items, ?Collection $articlesById = null, array $socialCountsByArticleId = []): array
    {
        $rows = [];

        foreach ($items as $item) {
            if (! $item instanceof SeoProjectArchiveItem) {
                continue;
            }

            $rows[] = $this->presentItem($item, $articlesById, $socialCountsByArticleId);
        }

        return $rows;
    }

    /**
     * @param  Collection<int, SeoArticle>|null  $articlesById
     * @param  array<int, int>  $socialCountsByArticleId
     * @return RowData
     */
    public function presentItem(SeoProjectArchiveItem $item, ?Collection $articlesById = null, array $socialCountsByArticleId = []): array
    {
        $snapshot = is_array($item->article_snapshot) ? $item->article_snapshot : [];
        $articleId = (int) ($item->article_id ?? ($snapshot['article_id'] ?? 0));

        $article = null;
        if ($articlesById instanceof Collection && $articleId > 0) {
            $candidate = $articlesById->get($articleId);
            $article = $candidate instanceof SeoArticle ? $candidate : null;
        } elseif ($item->relationLoaded('article')) {
            $article = $item->article instanceof SeoArticle ? $item->article : null;
        }

        $historical = $this->historicalFields->resolve($article, $snapshot);

        $title = $historical['title'];
        $slug = $this->firstNonEmpty([
            $snapshot['slug'] ?? null,
            $article?->slug,
        ]);
        $keyword = $historical['keyword'];
        $metaTitle = $this->firstNonEmpty([
            $snapshot['meta_title'] ?? null,
            $article?->title,
        ]);
        $metaDescription = $this->firstNonEmpty([
            $snapshot['meta_description'] ?? null,
            $this->articleMeta($article, 'seo_meta_description'),
        ]);
        $outlineMeta = $this->firstNonEmpty([
            $this->articleMeta($article, 'outline_meta'),
            $this->articleMeta($article, 'seo_outline'),
        ]);

        $bodyExcerpt = $this->buildBodyExcerpt($article, $snapshot);
        $seoScore = $snapshot['seo_score'] ?? null;
        if ($seoScore === null
            && $article instanceof SeoArticle
            && $article->relationLoaded('seoProfile')
        ) {
            $seoScore = $article->seoProfile?->seo_score;
        }
        $syncStatus = $this->firstNonEmpty([
            $snapshot['sync_status'] ?? null,
            ($article instanceof SeoArticle && $article->relationLoaded('wordpressLink'))
                ? $article->wordpressLink?->sync_status
                : null,
        ]);
        $wpPostId = $this->firstNonEmpty([
            $snapshot['wordpress_post_id'] ?? null,
            ($article instanceof SeoArticle && $article->relationLoaded('wordpressLink'))
                ? $article->wordpressLink?->wp_post_id
                : null,
        ]);
        $wpUrlString = $historical['wordpress_url'];
        $hasPublicWordpressUrl = $wpUrlString !== '';

        if ($article instanceof SeoArticle && $article->relationLoaded('indexHealth') && $article->indexHealth !== null) {
            $healthStatus = strtolower(trim((string) ($article->indexHealth->current_status ?? '')));
            if ($healthStatus === 'indexed') {
                $indexedAtRaw = $article->indexHealth->last_indexed_at
                    ?? ($article->relationLoaded('seoProfile') ? $article->seoProfile?->indexed_at : null)
                    ?? ($article->getAttributes()['indexed_at'] ?? null);
                $previousIndexedAtRaw = ($article->relationLoaded('seoProfile') ? $article->seoProfile?->previous_indexed_at : null)
                    ?? ($article->getAttributes()['previous_indexed_at'] ?? null);
            } elseif (in_array($healthStatus, ['not_indexed', 'dropped'], true)) {
                $indexedAtRaw = null;
                $previousIndexedAtRaw = $article->indexHealth->last_indexed_at
                    ?? ($article->relationLoaded('seoProfile') ? $article->seoProfile?->indexed_at : null)
                    ?? ($article->getAttributes()['indexed_at'] ?? ($snapshot['indexed_at'] ?? null));
            } elseif ($article->relationLoaded('seoProfile')) {
                $indexedAtRaw = $article->seoProfile?->indexed_at
                    ?? ($article->getAttributes()['indexed_at'] ?? null);
                $previousIndexedAtRaw = $article->seoProfile?->previous_indexed_at
                    ?? ($article->getAttributes()['previous_indexed_at'] ?? null);
            } else {
                $attrs = $article->getAttributes();
                $indexedAtRaw = $attrs['indexed_at'] ?? ($snapshot['indexed_at'] ?? null);
                $previousIndexedAtRaw = $attrs['previous_indexed_at'] ?? ($snapshot['previous_indexed_at'] ?? null);
            }
        } elseif ($article instanceof SeoArticle && $article->relationLoaded('seoProfile')) {
            $indexedAtRaw = $article->seoProfile?->indexed_at
                ?? ($article->getAttributes()['indexed_at'] ?? null);
            $previousIndexedAtRaw = $article->seoProfile?->previous_indexed_at
                ?? ($article->getAttributes()['previous_indexed_at'] ?? null);
        } elseif ($article instanceof SeoArticle) {
            $attrs = $article->getAttributes();
            $indexedAtRaw = $attrs['indexed_at'] ?? ($snapshot['indexed_at'] ?? null);
            $previousIndexedAtRaw = $attrs['previous_indexed_at'] ?? ($snapshot['previous_indexed_at'] ?? null);
        } else {
            $indexedAtRaw = $snapshot['indexed_at'] ?? null;
            $previousIndexedAtRaw = $snapshot['previous_indexed_at'] ?? null;
        }

        $articleExists = $article instanceof SeoArticle;
        $canEdit = false;
        $editUrl = null;

        if ($articleExists) {
            try {
                $canAccess = SeoAccessControl::canAccessArticle($article);
                $canEdit = $canAccess && ArticleResource::canEdit($article);
                if ($canEdit) {
                    $editUrl = ArticleResource::getUrl('edit', ['record' => $article->getKey()]);
                }
            } catch (Throwable) {
                // Pure PHPUnit / auth-factory chưa bind — giữ article_exists, không link.
                $canEdit = false;
                $editUrl = null;
            }
        }

        $taskStatus = (string) ($snapshot['status'] ?? '');
        if ($taskStatus === '' && $item->relationLoaded('task')) {
            $taskStatus = (string) ($item->task?->status ?? '');
        }

        $completedAt = $snapshot['completed_at'] ?? null;
        if ($completedAt === null && $item->relationLoaded('task')) {
            $completedAt = $item->task?->completed_at;
        }

        $internalFromArticle = null;
        $externalFromArticle = null;
        if ($article instanceof SeoArticle) {
            $attrs = $article->getAttributes();
            if (array_key_exists('internal_link_count', $attrs) && $attrs['internal_link_count'] !== null) {
                $internalFromArticle = (int) $attrs['internal_link_count'];
            }
            if (array_key_exists('external_link_count', $attrs) && $attrs['external_link_count'] !== null) {
                $externalFromArticle = (int) $attrs['external_link_count'];
            }
        }

        return [
            'item_id' => (int) $item->getKey(),
            'article_id' => $articleId,
            'position' => (int) ($item->position ?? 0),
            'title' => $title,
            'keyword' => $keyword,
            'slug' => is_string($slug) ? $slug : '',
            'meta_title' => is_string($metaTitle) ? $metaTitle : '',
            'meta_description' => is_string($metaDescription) ? $metaDescription : '',
            'outline_meta' => is_string($outlineMeta) ? $outlineMeta : '',
            'body_excerpt' => $bodyExcerpt,
            'image_count' => (int) ($snapshot['image_count'] ?? 0),
            'internal_link_count' => $this->resolveLinkCount(
                $snapshot['internal_link_count'] ?? null,
                $internalFromArticle,
            ),
            'external_link_count' => $this->resolveLinkCount(
                $snapshot['external_link_count'] ?? null,
                $externalFromArticle,
            ),
            'seo_score' => $seoScore !== null ? (float) $seoScore : null,
            'task_status' => $taskStatus,
            'review_status' => (string) ($snapshot['approved_status'] ?? $article?->review_status ?? ''),
            'sync_status' => is_string($syncStatus) ? $syncStatus : '',
            'wordpress_post_id' => $wpPostId !== null ? (int) $wpPostId : null,
            'wordpress_url' => $hasPublicWordpressUrl ? $wpUrlString : '',
            'has_public_wordpress_url' => $hasPublicWordpressUrl,
            'check_index_url' => $hasPublicWordpressUrl
                ? SeoAuditCheckIndexUrl::forCanonicalUrl($wpUrlString)
                : null,
            'wp_sync_error' => trim((string) ($snapshot['wp_sync_error'] ?? '')),
            'indexed_at' => $this->toIsoOrNull($indexedAtRaw),
            'previous_indexed_at' => $this->toIsoOrNull($previousIndexedAtRaw),
            'indexed_at_label' => self::formatIndexDate($indexedAtRaw),
            'previous_indexed_at_label' => self::formatIndexDate($previousIndexedAtRaw),
            'created_at' => SeoProjectResource::formatTaskTimestamp($snapshot['created_at'] ?? $article?->created_at),
            'updated_at' => SeoProjectResource::formatTaskTimestamp($snapshot['updated_at'] ?? $article?->updated_at),
            'completed_at' => SeoProjectResource::formatTaskTimestamp($completedAt),
            'last_saved_at' => SeoProjectResource::formatTaskTimestamp($snapshot['last_saved_at'] ?? null),
            'last_synced_at' => SeoProjectResource::formatTaskTimestamp(
                $snapshot['last_synced_at']
                    ?? (($article instanceof SeoArticle && $article->relationLoaded('wordpressLink'))
                        ? $article->wordpressLink?->last_synced_at
                        : null)
            ),
            'article_exists' => $articleExists,
            'can_edit' => $canEdit,
            'edit_url' => $editUrl,
            'social_links_count' => (int) ($socialCountsByArticleId[$articleId] ?? 0),
        ];
    }

    /**
     * @param  Collection<int, SeoProjectArchiveItem>  $items
     * @return Collection<int, SeoArticle>
     */
    public function loadArticlesById(Collection $items): Collection
    {
        $ids = [];
        foreach ($items as $item) {
            if (! $item instanceof SeoProjectArchiveItem) {
                continue;
            }
            $snapshot = is_array($item->article_snapshot) ? $item->article_snapshot : [];
            $id = (int) ($item->article_id ?? ($snapshot['article_id'] ?? 0));
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return collect();
        }

        return SeoArticle::query()
            ->whereIn('id', array_values($ids))
            ->with(['articleMetas', 'site', 'seoProfile', 'wordpressLink', 'indexHealth'])
            ->get()
            ->keyBy(static fn (SeoArticle $article): int => (int) $article->getKey());
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstPublicHttpUrl(array $values): string
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $url = trim($value);
            if ($this->isPublicHttpUrl($url)) {
                return $url;
            }
        }

        return '';
    }

    private function isPublicHttpUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    public static function formatIndexDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('d/m/Y');
        } catch (Throwable) {
            return null;
        }
    }

    private function toIsoOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if ($value instanceof Carbon) {
                return $value->toIso8601String();
            }

            return Carbon::parse((string) $value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveLinkCount(mixed $snapshotValue, mixed $articleValue): int
    {
        if ($snapshotValue !== null && $snapshotValue !== '') {
            return max(0, (int) $snapshotValue);
        }

        if ($articleValue !== null && $articleValue !== '') {
            return max(0, (int) $articleValue);
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function buildBodyExcerpt(?SeoArticle $article, array $snapshot): string
    {
        $fromSnapshot = trim((string) ($snapshot['body_excerpt'] ?? $snapshot['excerpt'] ?? ''));
        if ($fromSnapshot !== '') {
            return html_entity_decode(Str::limit(strip_tags($fromSnapshot), 800), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (! $article instanceof SeoArticle) {
            return '';
        }

        $text = trim(strip_tags((string) ($article->body ?? '')));
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($text === '') {
            return '';
        }

        return Str::limit($text, 800);
    }

    private function articleMeta(?SeoArticle $article, string $key): ?string
    {
        if (! $article instanceof SeoArticle) {
            return null;
        }

        // Pure PHPUnit / no lazy-load: chỉ đọc relation đã eager-load.
        if (! $article->relationLoaded('articleMetas')) {
            return null;
        }

        $value = $article->articleMetas->firstWhere('meta_key', $key)?->meta_value;

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstNonEmpty(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            return $value;
        }

        return null;
    }
}
