<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Contracts;

/**
 * WordPress-owned taxonomy catalog for publishing selectors.
 * Content/Publishing consume this port; they do not call Bridge REST directly.
 */
interface PublishingTaxonomyCatalog
{
    public const TAXONOMY_CATEGORY = 'category';

    public const TAXONOMY_POST_TAG = 'post_tag';

    public const TAXONOMY_PRODUCT_CAT = 'product_cat';

    public const TAXONOMY_PRODUCT_TAG = 'product_tag';

    /** @var list<string> */
    public const SUPPORTED = [
        self::TAXONOMY_CATEGORY,
        self::TAXONOMY_POST_TAG,
        self::TAXONOMY_PRODUCT_CAT,
        self::TAXONOMY_PRODUCT_TAG,
    ];

    public function getTerms(int $siteId, string $taxonomy): PublishingTaxonomyCatalogResult;
}
