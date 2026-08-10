<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\AiPrompt\Services\SeoApiConnectionProviderCatalog;

/**
 * @deprecated Use {@see SeoApiConnectionProviderCatalog}.
 * Name collided with Extension\Registry\SeoProviderRegistry (SDK drivers).
 * This subclass keeps FQCN compatibility for existing DI/imports.
 */
final class SeoProviderRegistry extends SeoApiConnectionProviderCatalog
{
}
