<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscMappingStatus: string
{
    case Candidate = 'candidate';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Stale = 'stale';
}
