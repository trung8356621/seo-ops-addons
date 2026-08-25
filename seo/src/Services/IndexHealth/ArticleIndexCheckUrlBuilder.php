<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\IndexHealth;

/**
 * Manual Google site: helper URL — no scrape, no HTTP, no persistence.
 */
final class ArticleIndexCheckUrlBuilder
{
    public function forCanonicalUrl(?string $canonicalUrl): ?string
    {
        $url = trim((string) $canonicalUrl);
        if ($url === '') {
            return null;
        }

        return 'https://www.google.com/search?q='.rawurlencode('site:'.$url);
    }
}
