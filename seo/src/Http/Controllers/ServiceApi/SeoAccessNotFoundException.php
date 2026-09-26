<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Controllers\ServiceApi;

use RuntimeException;

/**
 * Internal signal for temporary SEO Access 404 responses.
 */
final class SeoAccessNotFoundException extends RuntimeException
{
}
