<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit;

use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexCheckUrlBuilder;

/**
 * Manual Google site: check — no scrape, no persistence.
 * Canonical builder: ArticleIndexCheckUrlBuilder.
 */
final class SeoAuditCheckIndexUrl
{
    public static function forCanonicalUrl(?string $canonicalUrl): ?string
    {
        return (new ArticleIndexCheckUrlBuilder)->forCanonicalUrl($canonicalUrl);
    }
}
