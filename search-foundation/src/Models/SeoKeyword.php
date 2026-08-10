<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Models;

/**
 * Alias model — bảng DB là `keywords`.
 *
 * @property string $phrase
 * @property string $type
 * @property string|null $target_url
 */
class SeoKeyword extends Keyword
{
}
