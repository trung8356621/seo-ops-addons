<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Support;

use Omnichannel\Addons\Publishing\Contracts\PublishingTaxonomyCatalog;
use Omnichannel\Addons\Publishing\Contracts\PublishingTaxonomyCatalogResult;

/**
 * Default port when WordPress addon has not bound a catalog implementation.
 */
final class UnavailablePublishingTaxonomyCatalog implements PublishingTaxonomyCatalog
{
    public function getTerms(int $siteId, string $taxonomy): PublishingTaxonomyCatalogResult
    {
        unset($siteId);

        return PublishingTaxonomyCatalogResult::unavailable(
            strtolower(trim($taxonomy)),
            'catalog_unbound',
            'WordPress taxonomy catalog is not registered.',
        );
    }
}
