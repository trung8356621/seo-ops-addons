<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Topic;

/**
 * Membership provenance for seo_topic_keywords.source.
 */
final class TopicKeywordSource
{
    public const LINK_LIST = 'link_list';

    public const PRODUCT_CAT = 'product_cat';

    public const RECLUSTER = 'recluster';

    public const MANUAL = 'manual';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::LINK_LIST, self::PRODUCT_CAT, self::RECLUSTER, self::MANUAL];
    }
}
