<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Support\WordPressPermalinkBuilder;

/**
 * Historical article fields for archived export/preview.
 *
 * Priority matches ArchivePreviewArticlePresenter:
 * snapshot (persisted at archive time) first, then live article when still present.
 *
 * Never uses SeoProjectTask planning title/keyword.
 */
final class ArchiveArticleHistoricalFieldResolver
{
    public function __construct(
        private readonly ?WordPressPermalinkBuilder $permalinkBuilder = null,
        private readonly ContentProjectExportReviewedAtResolver $reviewedAtResolver = new ContentProjectExportReviewedAtResolver(),
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot  SeoProjectArchiveItem.article_snapshot
     * @return array{
     *     title: string,
     *     keyword: string,
     *     wordpress_url: string,
     *     reviewed_at: mixed,
     *     last_update_wp: mixed,
     *     wp_created_at: mixed,
     *     indexed_at: mixed,
     *     previous_indexed_at: mixed
     * }
     */
    public function resolve(?SeoArticle $article, array $snapshot): array
    {
        $live = $this->liveArticleFields($article);

        $title = $this->firstNonEmptyString([
            $snapshot['title'] ?? null,
            $live['title'],
        ]);
        $keyword = $this->firstNonEmptyString([
            $snapshot['primary_keyword'] ?? null,
            $live['keyword'],
        ]);
        $wordpressUrl = $this->firstPublicHttpUrl([
            $live['wordpress_url'],
            $snapshot['wordpress_url'] ?? null,
        ]);

        $reviewedBag = [
            'reviewed_at' => $snapshot['reviewed_at'] ?? $live['reviewed_at'],
            'last_update_wp' => $snapshot['last_update_wp'] ?? $live['last_update_wp'],
            'wp_created_at' => $snapshot['wp_created_at'] ?? $live['wp_created_at'],
        ];

        $indexedAt = $this->firstNonEmpty([
            $live['indexed_at'],
            $snapshot['indexed_at'] ?? null,
        ]);
        $previousIndexedAt = $this->firstNonEmpty([
            $live['previous_indexed_at'],
            $snapshot['previous_indexed_at'] ?? null,
        ]);

        return [
            'title' => $title,
            'keyword' => $keyword,
            'wordpress_url' => $wordpressUrl,
            'reviewed_at' => $this->reviewedAtResolver->resolve($reviewedBag),
            'last_update_wp' => $reviewedBag['last_update_wp'],
            'wp_created_at' => $reviewedBag['wp_created_at'],
            'indexed_at' => $indexedAt,
            'previous_indexed_at' => $previousIndexedAt,
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     keyword: string,
     *     wordpress_url: string,
     *     reviewed_at: mixed,
     *     last_update_wp: mixed,
     *     wp_created_at: mixed,
     *     indexed_at: mixed,
     *     previous_indexed_at: mixed
     * }
     */
    private function liveArticleFields(?SeoArticle $article): array
    {
        if (! $article instanceof SeoArticle) {
            return [
                'title' => '',
                'keyword' => '',
                'wordpress_url' => '',
                'reviewed_at' => null,
                'last_update_wp' => null,
                'wp_created_at' => null,
                'indexed_at' => null,
                'previous_indexed_at' => null,
            ];
        }

        $title = trim((string) ($article->title ?? ''));
        $keyword = trim((string) ($this->articleMeta($article, 'seo_focus_keyword') ?? ''));
        $permalink = trim((string) ($this->articleMeta($article, 'wp_permalink') ?? ''));
        $wordpressUrl = '';
        if ($this->permalinkBuilder instanceof WordPressPermalinkBuilder) {
            $wordpressUrl = trim($this->permalinkBuilder->resolve(
                $article,
                $permalink,
                trim((string) ($article->slug ?? '')) !== '' ? (string) $article->slug : null,
            ));
        } elseif ($permalink !== '' && filter_var($permalink, FILTER_VALIDATE_URL) !== false) {
            $wordpressUrl = $permalink;
        }

        $indexedAt = null;
        $previousIndexedAt = null;
        if ($article->relationLoaded('seoProfile')) {
            $indexedAt = $article->seoProfile?->indexed_at;
            $previousIndexedAt = $article->seoProfile?->previous_indexed_at;
        }

        return [
            'title' => $title,
            'keyword' => $keyword,
            'wordpress_url' => $wordpressUrl,
            'reviewed_at' => $article->getAttribute('reviewed_at'),
            'last_update_wp' => $this->safeLastUpdateWp($article),
            'wp_created_at' => $article->getAttribute('wp_created_at'),
            'indexed_at' => $indexedAt,
            'previous_indexed_at' => $previousIndexedAt,
        ];
    }

    private function safeLastUpdateWp(SeoArticle $article): mixed
    {
        $explicit = $article->getAttribute('last_update_wp');
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        if (! $article->relationLoaded('wordpressLink')) {
            return null;
        }

        $link = $article->wordpressLink;

        return $link?->observed_modified_at ?? $link?->external_modified_at;
    }

    private function articleMeta(SeoArticle $article, string $key): ?string
    {
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
    private function firstNonEmptyString(array $values): string
    {
        $value = $this->firstNonEmpty($values);

        return is_string($value) ? trim($value) : (is_scalar($value) ? trim((string) $value) : '');
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
            if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)) {
                return $url;
            }
        }

        return '';
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
