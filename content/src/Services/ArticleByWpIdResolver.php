<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use App\Models\Site;

/**
 * Resolve local article by WordPress id + canonical classification identity.
 *
 * Identity = site_id + wp_post_id + wp_is_term (+ preferred content_type).
 * Legacy preferredType labels (article/category/product_category) are mapped.
 */
final class ArticleByWpIdResolver
{
    /**
     * @return list<array{content_type: ContentType, wp_is_term: bool}>
     */
    public function candidateIdentities(string $preferredType): array
    {
        $normalized = $this->normalizeType($preferredType);
        $primary = ArticleContentClassification::fromTaskPostType($normalized);

        $candidates = [[
            'content_type' => $primary['content_type'],
            'wp_is_term' => $primary['wp_is_term'],
        ]];

        // Soft fallbacks when preferred label was ambiguous historically.
        if ($normalized === 'product') {
            $candidates[] = ['content_type' => ContentType::Post, 'wp_is_term' => false];
        } elseif ($normalized === 'product_category') {
            $candidates[] = ['content_type' => ContentType::Post, 'wp_is_term' => true];
        } elseif ($normalized === 'category') {
            $candidates[] = ['content_type' => ContentType::Product, 'wp_is_term' => true];
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            $key = $candidate['content_type']->value.'|'.($candidate['wp_is_term'] ? '1' : '0');
            $unique[$key] = $candidate;
        }

        return array_values($unique);
    }

    /**
     * @deprecated Use {@see candidateIdentities()}.
     *
     * @return list<string>
     */
    public function candidateTypes(string $preferredType): array
    {
        $preferredType = $this->normalizeType($preferredType);

        $fallbacks = match ($preferredType) {
            'product' => ['article'],
            'product_category' => ['category'],
            'category' => ['product_category'],
            default => [],
        };

        return array_values(array_unique([$preferredType, ...$fallbacks]));
    }

    public function resolve(Site $site, int $wpId, string $preferredType): ?SeoArticle
    {
        if ($wpId <= 0) {
            return null;
        }

        foreach ($this->candidateIdentities($preferredType) as $identity) {
            $query = SeoArticle::query()
                ->where('site_id', $site->id)
                ->whereWpPostId($wpId);

            ArticleContentClassification::scopeContentType($query, $identity['content_type']);
            if ($identity['wp_is_term']) {
                ArticleContentClassification::scopeIsTerm($query, true);
            } else {
                ArticleContentClassification::scopeNonTerm($query);
            }

            $article = $query->first();
            if ($article instanceof SeoArticle) {
                return $article;
            }
        }

        // Last resort: any article with this WP id on the site (pre-backfill rows).
        return SeoArticle::query()
            ->where('site_id', $site->id)
            ->whereWpPostId($wpId)
            ->first();
    }

    public function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'product', 'page', 'post' => $type,
            'category' => 'category',
            'product_category', 'product_cat' => 'product_category',
            'article' => 'article',
            default => 'article',
        };
    }
}
