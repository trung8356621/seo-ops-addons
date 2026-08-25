<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\IndexHealth;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleMetaMap;

/**
 * Resolve canonical public URL for Index Health (never invent from slug).
 */
final class ArticleIndexCanonicalUrlResolver
{
    public function resolve(SeoArticle $article): ?string
    {
        $article->loadMissing('wordpressLink', 'articleMetas');

        $candidates = [
            trim((string) ($article->wordpressLink?->observed_permalink ?? '')),
            trim((string) (ArticleMetaMap::for($article)->get('wp_permalink', '') ?? '')),
        ];

        foreach ($candidates as $url) {
            if ($this->isPublicHttpUrl($url)) {
                return $url;
            }
        }

        return null;
    }

    public function isPublicHttpUrl(string $url): bool
    {
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));

        return in_array($scheme, ['http', 'https'], true);
    }
}
