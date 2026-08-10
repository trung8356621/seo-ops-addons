<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence;

enum KeywordTopicalMapVersionStatus: string
{
    case Draft = 'draft';
    case Reviewed = 'reviewed';
    case Approved = 'approved';
    case Superseded = 'superseded';
}
