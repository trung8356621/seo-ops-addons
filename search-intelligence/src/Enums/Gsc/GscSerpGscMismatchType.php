<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscSerpGscMismatchType: string
{
    case IntentMismatch = 'intent_mismatch';
    case PositionMismatch = 'position_mismatch';
    case ImpressionWithoutSerpPresence = 'impression_without_serp_presence';
    case SerpPresenceWithoutImpression = 'serp_presence_without_impression';
    case PageTypeMismatch = 'page_type_mismatch';
}
