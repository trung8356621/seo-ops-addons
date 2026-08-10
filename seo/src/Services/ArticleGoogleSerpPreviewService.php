<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\WordPress\Services\VirtualCommentService;
use App\Support\RankMathSchemaParser;

final class ArticleGoogleSerpPreviewService
{
    public function __construct(
        private readonly RankMathSchemaParser $schemaParser,
        private readonly VirtualCommentService $virtualComments,
    ) {}

    /**
     * @return array{
     *     type: string,
     *     title: string,
     *     url: string,
     *     description: string,
     *     display_url: string,
     *     meta: array<string, mixed>
     * }
     */
    public function buildForArticle(
        SeoArticle $article,
        string $seoTitle,
        string $seoDescription,
        string $permalink,
    ): array {
        $article->loadMissing('articleMetas');

        $parsed = $this->schemaParser->parse(
            $this->syntheticSchemaJson($article, $seoTitle, $seoDescription, $permalink),
        );

        $title = trim($seoTitle);
        if ($title === '') {
            $title = trim((string) ($parsed['title'] ?? ''));
        }
        if ($title === '') {
            $title = trim((string) ($article->title ?? ''));
        }

        $description = trim($seoDescription);
        if ($description === '') {
            $description = trim((string) ($parsed['description'] ?? ''));
        }

        $url = trim($permalink);
        if ($url === '') {
            $url = trim((string) ($parsed['url'] ?? ''));
        }

        if ($parsed['type'] === 'unknown' && $this->articleIsProduct($article)) {
            $parsed['type'] = 'product';
        }

        if ($parsed['type'] === 'unknown') {
            $parsed['type'] = 'article';
        }

        $preview = [
            'type' => (string) $parsed['type'],
            'title' => $title,
            'url' => $url,
            'description' => $description,
            'display_url' => $this->formatDisplayUrl($url),
            'meta' => is_array($parsed['meta'] ?? null) ? $parsed['meta'] : [],
        ];

        if ($preview['type'] === 'product' || $this->articleIsProduct($article)) {
            $preview['type'] = 'product';
            $preview = $this->enrichProductPreviewMeta($article, $preview);
        }

        return $preview;
    }

    /**
     * @param  array{type: string, title: string, url: string, description: string, display_url: string, meta: array<string, mixed>}  $preview
     * @return array{type: string, title: string, url: string, description: string, display_url: string, meta: array<string, mixed>}
     */
    private function enrichProductPreviewMeta(SeoArticle $article, array $preview): array
    {
        $meta = is_array($preview['meta'] ?? null) ? $preview['meta'] : [];

        $ratingValue = isset($meta['rating_value']) ? (float) $meta['rating_value'] : null;
        $reviewCount = isset($meta['review_count']) ? (int) $meta['review_count'] : null;

        if ($ratingValue === null || $reviewCount === null || $reviewCount <= 0) {
            $stats = $this->resolveVirtualReviewStats($article);
            if ($stats !== null) {
                $meta['rating_value'] = $stats['average'];
                $meta['review_count'] = $stats['count'];
            }
        }

        $priceDisplay = trim((string) ($meta['price'] ?? ''));
        if ($priceDisplay === '') {
            $offerDisplay = $this->resolveProductOfferDisplay($article);
            if ($offerDisplay !== '') {
                $meta['price'] = $offerDisplay;
            }
        }

        if (trim((string) ($meta['availability_label'] ?? '')) === '') {
            $meta['availability_label'] = 'In stock';
        }

        $preview['meta'] = $meta;

        return $preview;
    }

    /**
     * @return array{average: float, count: int}|null
     */
    private function resolveVirtualReviewStats(SeoArticle $article): ?array
    {
        $reviews = $this->virtualComments->getForEditor($article);
        if ($reviews === []) {
            return null;
        }

        $sum = 0;
        foreach ($reviews as $row) {
            $sum += max(1, min(5, (int) ($row['rating'] ?? 5)));
        }

        $count = count($reviews);

        return [
            'average' => round($sum / $count, 1),
            'count' => $count,
        ];
    }

    private function syntheticSchemaJson(
        SeoArticle $article,
        string $seoTitle,
        string $seoDescription,
        string $permalink,
    ): string {
        $title = trim($seoTitle) !== '' ? trim($seoTitle) : trim((string) ($article->title ?? ''));
        $description = trim($seoDescription);
        $url = trim($permalink);

        $node = [
            'name' => $title,
            'description' => $description,
            'url' => $url,
        ];

        if ($this->articleIsProduct($article)) {
            $node['@type'] = 'Product';

            $offers = $this->resolveProductOffersNode($article);
            if ($offers !== null) {
                $node['offers'] = $offers;
            }

            $stats = $this->resolveVirtualReviewStats($article);
            if ($stats !== null && $stats['count'] > 0) {
                $node['aggregateRating'] = [
                    '@type' => 'AggregateRating',
                    'ratingValue' => (string) $stats['average'],
                    'reviewCount' => (string) $stats['count'],
                ];
            }
        } else {
            $node['@type'] = 'BlogPosting';
            $node['headline'] = $title;
        }

        return (string) json_encode([
            '@context' => 'https://schema.org',
            '@graph' => [$node],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveProductOffersNode(SeoArticle $article): ?array
    {
        $currency = strtoupper(trim((string) (
            $article->articleMetas->firstWhere('meta_key', 'price_currency')?->meta_value ?? 'VND'
        )));
        if ($currency === '') {
            $currency = 'VND';
        }

        $min = $this->metaFloat($article, ['min_price', 'low_price']);
        $max = $this->metaFloat($article, ['max_price', 'high_price']);

        if ($min !== null && $max !== null) {
            return [
                '@type' => 'AggregateOffer',
                'lowPrice' => (string) $min,
                'highPrice' => (string) $max,
                'priceCurrency' => $currency,
                'availability' => 'https://schema.org/InStock',
            ];
        }

        $price = $this->metaFloat($article, ['_price', 'price', '_sale_price', 'sale_price', 'regular_price', '_regular_price', 'wp_price']);
        if ($price === null) {
            return null;
        }

        return [
            '@type' => 'Offer',
            'price' => (string) $price,
            'priceCurrency' => $currency,
            'availability' => 'https://schema.org/InStock',
        ];
    }

    private function resolveProductOfferDisplay(SeoArticle $article): string
    {
        $parsed = $this->schemaParser->parse($this->syntheticSchemaJson($article, '', '', ''));

        return trim((string) ($parsed['meta']['price'] ?? ''));
    }

    /**
     * @param  list<string>  $keys
     */
    private function metaFloat(SeoArticle $article, array $keys): ?float
    {
        foreach ($keys as $key) {
            $raw = $article->articleMetas->firstWhere('meta_key', $key)?->meta_value;
            if ($raw === null || $raw === '' || ! is_numeric($raw)) {
                continue;
            }

            return (float) $raw;
        }

        return null;
    }

    private function articleIsProduct(SeoArticle $article): bool
    {
        return ArticlePostTypeResolver::resolve($article) === 'product';
    }

    private function formatDisplayUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return 'www.example.com';
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return $url;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') {
            return $host;
        }

        return $host.' › '.implode(' › ', array_filter(explode('/', $path)));
    }
}
