<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Enums;

enum SeoLinkMapDestinationKind: string
{
    case Content   = 'content';    // article / page / post
    case Reference = 'reference';  // wiki / trusted reference
    case Social    = 'social';     // social network
    case Contact   = 'contact';    // tel / mailto / whatsapp / zalo
    case Other     = 'other';      // uncategorised
}
