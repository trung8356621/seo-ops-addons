<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Enums;

enum SeoLinkMapType: string
{
    case Internal = 'internal';
    case External = 'external';
    case WikiTrust = 'wiki_trust';
}
