<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use App\Core\Addon\AddonEnablement;

/**
 * Thin alias — prefer App\Core\Addon\AddonEnablement::seoStackEnabled() for new call sites.
 */
final class SeoStackAvailability
{
    public static function enabled(): bool
    {
        return AddonEnablement::seoStackEnabled();
    }
}
