<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Slice;

/**
 * Allowlisted context slice keys.
 */
final class ContextSliceKey
{
    public const SITE_HEALTH = 'site.health';

    public const SITE_INDEXABILITY = 'site.indexability';

    public const SITE_SYNC = 'site.sync';

    public const CONTENT_INVENTORY = 'content.inventory';

    public const CONTENT_DISTRIBUTION = 'content.distribution';

    public const SEO_FINDINGS = 'seo.findings';

    public const SEO_INTERNAL_LINKS = 'seo.internal_links';

    public const PUBLISHING_STATUS = 'publishing.status';

    public const KEYWORDS_LANDSCAPE = 'keywords.landscape';

    public const KEYWORDS_RELATIONSHIP = 'keywords.relationship';

    public const GSC_PERFORMANCE = 'gsc.performance';

    public const GSC_OPPORTUNITIES = 'gsc.opportunities';

    public const GSC_CANNIBALIZATION = 'gsc.cannibalization';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SITE_HEALTH,
            self::SITE_INDEXABILITY,
            self::SITE_SYNC,
            self::CONTENT_INVENTORY,
            self::CONTENT_DISTRIBUTION,
            self::SEO_FINDINGS,
            self::SEO_INTERNAL_LINKS,
            self::PUBLISHING_STATUS,
            self::KEYWORDS_LANDSCAPE,
            self::KEYWORDS_RELATIONSHIP,
            self::GSC_PERFORMANCE,
            self::GSC_OPPORTUNITIES,
            self::GSC_CANNIBALIZATION,
        ];
    }
}
